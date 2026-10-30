<?php

declare(strict_types=1);

namespace Rekhu\Assistant\Tests\Feature;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Rekhu\Assistant\Runtime\Conversation;
use Rekhu\Assistant\Runtime\Turn;
use Rekhu\Assistant\Tests\TestCase;

final class TurnLimitsTest extends TestCase
{
    public function test_an_answer_cut_off_at_its_length_runs_none_of_its_actions_and_goes_on(): void
    {
        $this->rekhuStreams(
            $this->stream([['type' => 'text', 'text' => 'Looking them up. '], ['type' => 'tool_use', 'id' => 'tu_cut', 'name' => 'help', 'input' => ['question' => 'exp']]], 'max_tokens'),
            $this->stream([['type' => 'text', 'text' => 'Done in smaller steps.']], 'end_turn'),
        );

        $events = $this->events($this->actingAs($this->user())->post('/rekhu/assistant/chat', ['message' => 'Export all of them', 'conversation' => 'conv-cut-0001'], $this->headers())->streamedContent());

        $this->assertNotContains('error', array_column($events, 'type'));
        $sent = $this->sent();
        $this->assertCount(2, $sent);
        $second = json_encode($sent[1]['messages']);
        $this->assertStringNotContainsString('tu_cut', $second);
        $this->assertStringContainsString('cut off', $second);
        $this->assertStringContainsString('Looking them up.', $second);
    }

    public function test_when_the_steps_run_out_the_user_reads_how_to_go_on(): void
    {
        config(['rekhu-assistant.max_steps' => 2]);
        $call = fn ($id) => $this->stream([['type' => 'tool_use', 'id' => $id, 'name' => 'help', 'input' => ['question' => 'x']]], 'tool_use');
        $this->rekhuStreams($call('tu_1'), $call('tu_2'));

        $events = $this->events($this->actingAs($this->user())->post('/rekhu/assistant/chat', ['message' => 'Do everything', 'conversation' => 'conv-steps-0001'], $this->headers())->streamedContent());

        $said = implode('', array_column(array_filter($events, fn ($e) => $e['type'] === 'text'), 'text'));
        $this->assertStringContainsString(__('rekhu-assistant::messages.too_big'), $said);
        $history = $this->actingAs($this->user())->get('/rekhu/assistant/chat?conversation=conv-steps-0001', ['Accept' => 'application/json'] + $this->headers())->json('messages');
        $this->assertSame(__('rekhu-assistant::messages.too_big'), end($history)['text']);
    }

    public function test_a_stored_action_without_its_result_is_left_out_so_the_conversation_goes_on(): void
    {
        $conversation = Conversation::open($this->user(), 'conv-heal-0001');
        $conversation->messages = [
            ['role' => 'user', 'content' => 'Export the files', 'id' => 'm_1'],
            ['role' => 'assistant', 'content' => [['type' => 'text', 'text' => 'On it.'], ['type' => 'tool_use', 'id' => 'tu_orphan', 'name' => 'help', 'input' => []]], 'id' => 'm_2'],
        ];
        $conversation->save();
        $this->rekhuStreams($this->stream([['type' => 'text', 'text' => 'Here they are.']], 'end_turn'));

        $this->actingAs($this->user())->post('/rekhu/assistant/chat', ['message' => 'I did not get them', 'conversation' => 'conv-heal-0001'], $this->headers())->streamedContent();

        $messages = $this->sent()[0]['messages'];
        $this->assertStringNotContainsString('tu_orphan', json_encode($messages));
        $this->assertSame(['user', 'assistant', 'user'], array_column($messages, 'role'));
    }

    #[DataProvider('failures')]
    public function test_the_user_reads_what_a_failure_means_for_them(int $status, string $type, string $key): void
    {
        $this->rekhuRefuses($status, $type);

        $events = $this->events($this->actingAs($this->user())->post('/rekhu/assistant/chat', ['message' => 'Hi'], $this->headers())->streamedContent());

        $this->assertSame(__('rekhu-assistant::messages.'.$key), end($events)['message']);
    }

    public static function failures(): array
    {
        return [
            'paused' => [402, 'app_limit', 'paused'],
            'credit' => [402, 'billing_error', 'credit'],
            'busy' => [529, 'overloaded_error', 'busy'],
            'refused conversation' => [400, 'invalid_request_error', 'conversation'],
            'down' => [502, 'api_error', 'unavailable'],
        ];
    }

    public function test_the_turn_tells_the_model_what_a_cut_off_means(): void
    {
        $this->assertStringContainsString('none of the actions in it ran', Turn::CUT_OFF);
    }

    private function rekhuStreams(string ...$streams): void
    {
        $sequence = Http::sequence();
        foreach ($streams as $stream) {
            $sequence->push($stream, 200, ['Content-Type' => 'text/event-stream']);
        }
        Http::fake(['rekhu.test/*' => $sequence]);
    }

    /** @return list<array<string, mixed>> the bodies of the model calls, in order */
    private function sent(): array
    {
        return array_values(array_map(fn ($pair) => json_decode($pair[0]->body(), true), Http::recorded(fn (Request $r) => str_ends_with($r->url(), '/v1/messages'))->all()));
    }

    private function stream(array $blocks, string $stop): string
    {
        $events = [['type' => 'message_start', 'message' => ['content' => []]]];
        foreach ($blocks as $i => $block) {
            if ($block['type'] === 'text') {
                $events[] = ['type' => 'content_block_start', 'index' => $i, 'content_block' => ['type' => 'text', 'text' => '']];
                $events[] = ['type' => 'content_block_delta', 'index' => $i, 'delta' => ['type' => 'text_delta', 'text' => $block['text']]];
            } else {
                $events[] = ['type' => 'content_block_start', 'index' => $i, 'content_block' => ['type' => 'tool_use', 'id' => $block['id'], 'name' => $block['name'], 'input' => []]];
                $events[] = ['type' => 'content_block_delta', 'index' => $i, 'delta' => ['type' => 'input_json_delta', 'partial_json' => json_encode((object) $block['input'])]];
            }
            $events[] = ['type' => 'content_block_stop', 'index' => $i];
        }
        $events[] = ['type' => 'message_delta', 'delta' => ['stop_reason' => $stop]];
        $events[] = ['type' => 'message_stop'];

        return implode('', array_map(fn ($e) => 'event: '.$e['type']."\ndata: ".json_encode($e)."\n\n", $events));
    }
}
