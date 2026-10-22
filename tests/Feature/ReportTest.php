<?php

declare(strict_types=1);

namespace Rekhu\Assistant\Tests\Feature;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Rekhu\Assistant\Events\AnswerReported;
use Rekhu\Assistant\Runtime\Conversation;
use Rekhu\Assistant\Tests\TestCase;

final class ReportTest extends TestCase
{
    private const CONVERSATION = 'conv-12345678';

    /** Rekhu answers model calls with $text and takes reports with $status. */
    private function rekhu(string $text = 'Revenue in May was 38,000.', int $status = 201): void
    {
        $events = [
            ['type' => 'content_block_start', 'index' => 0, 'content_block' => ['type' => 'text', 'text' => '']],
            ['type' => 'content_block_delta', 'index' => 0, 'delta' => ['type' => 'text_delta', 'text' => $text]],
            ['type' => 'content_block_stop', 'index' => 0],
            ['type' => 'message_delta', 'delta' => ['stop_reason' => 'end_turn']],
        ];
        Http::fake([
            'rekhu.test/v1/messages' => Http::response(implode('', array_map(fn ($e) => 'event: '.$e['type']."\ndata: ".json_encode($e)."\n\n", $events)), 200, ['Content-Type' => 'text/event-stream']),
            'rekhu.test/v1/reports' => Http::response($status === 201 ? ['report' => ['id' => 1]] : ['error' => ['message' => 'down']], $status),
        ]);
    }

    /** One question and its answer in the user's conversation; returns the answer's id from the stream. */
    private function ask(int $user = 7, string $question = 'What was the revenue in May?'): string
    {
        $stream = $this->actingAs($this->user($user))->post('/rekhu/assistant/chat', ['message' => $question, 'conversation' => self::CONVERSATION], $this->headers())->streamedContent();
        $done = collect($this->events($stream))->firstWhere('type', 'done');

        return $done['message'];
    }

    private function report(array $body, int $user = 7)
    {
        return $this->actingAs($this->user($user))->postJson('/rekhu/assistant/chat/report', $body + ['conversation' => self::CONVERSATION, 'reason' => 'wrong_answer'], ['X-Rekhu-Protocol' => '1']);
    }

    public function test_the_stream_and_the_history_name_each_message(): void
    {
        $this->rekhu();
        $id = $this->ask();

        $this->assertSame('m_2', $id);
        $this->actingAs($this->user())->getJson('/rekhu/assistant/chat?conversation='.self::CONVERSATION)
            ->assertJsonPath('messages', [
                ['id' => 'm_1', 'role' => 'user', 'text' => 'What was the revenue in May?'],
                ['id' => 'm_2', 'role' => 'bot', 'text' => 'Revenue in May was 38,000.'],
            ])
            ->assertJsonPath('report.max_comment', 1000)
            ->assertJsonPath('report.reasons.0', ['key' => 'wrong_answer', 'label' => 'The answer is wrong']);
    }

    public function test_a_report_is_built_from_the_servers_own_conversation(): void
    {
        $this->rekhu();
        $id = $this->ask();

        $this->report(['message' => $id, 'comment' => 'It was 41,200.', 'answer' => 'something the browser made up'])
            ->assertCreated()
            ->assertExactJson(['message' => 'Thanks, we will look into it.']);

        Http::assertSent(fn (Request $r) => $r->url() === 'https://rekhu.test/v1/reports'
            && $r->header('x-api-key')[0] === self::KEY
            && $r->header('x-user')[0] === '7'
            && $r->header('x-session')[0] === 'assistant-'.self::CONVERSATION
            && $r['conversation'] === self::CONVERSATION
            && $r['message'] === 'm_2'
            && $r['reason'] === 'wrong_answer'
            && $r['comment'] === 'It was 41,200.'
            && $r['question'] === 'What was the revenue in May?'
            && $r['answer'] === 'Revenue in May was 38,000.'
            && $r['transcript'] === [['role' => 'user', 'text' => 'What was the revenue in May?'], ['role' => 'bot', 'text' => 'Revenue in May was 38,000.']]
            && $r['tools'] === []);
    }

    public function test_a_user_cannot_report_another_users_conversation(): void
    {
        $this->rekhu();
        $id = $this->ask(1);

        $this->report(['message' => $id], 2)->assertNotFound();
        Http::assertNotSent(fn (Request $r) => str_ends_with($r->url(), '/v1/reports'));
    }

    public function test_a_message_that_is_not_an_answer_of_the_conversation_is_not_found(): void
    {
        $this->rekhu();
        $this->ask();

        $this->report(['message' => 'm_99'])->assertNotFound()->assertJsonPath('message', 'There is no such answer in this conversation.');
        $this->report(['message' => 'm_1'])->assertNotFound();   // the user's own question
    }

    public function test_a_reason_must_be_one_of_the_configured_ones(): void
    {
        $this->rekhu();
        $id = $this->ask();

        $this->report(['message' => $id, 'reason' => 'rude'])->assertStatus(422)->assertJsonValidationErrors('reason');
    }

    public function test_an_app_adds_its_own_reason_with_its_own_label(): void
    {
        config(['rekhu-assistant.reports.reasons.wrong_numbers' => ['en' => 'The numbers are wrong', 'ar' => 'الأرقام غير صحيحة']]);
        $this->rekhu();
        $id = $this->ask();

        app()->setLocale('ar');
        $this->actingAs($this->user())->getJson('/rekhu/assistant/chat?conversation='.self::CONVERSATION)
            ->assertJsonPath('report.reasons.4', ['key' => 'wrong_numbers', 'label' => 'الأرقام غير صحيحة'])
            ->assertJsonPath('report.reasons.0.label', 'الإجابة خاطئة');
        $this->report(['message' => $id, 'reason' => 'wrong_numbers'])->assertCreated()->assertJsonPath('message', 'شكراً، سنراجع ذلك.');
    }

    public function test_a_comment_over_the_limit_is_refused(): void
    {
        config(['rekhu-assistant.reports.max_comment' => 10]);
        $this->rekhu();
        $id = $this->ask();

        $this->report(['message' => $id, 'comment' => str_repeat('a', 11)])->assertStatus(422)->assertJsonValidationErrors('comment');
    }

    public function test_reports_are_throttled_per_user(): void
    {
        config(['rekhu-assistant.reports.per_user_per_hour' => 2]);
        $this->rekhu();
        $id = $this->ask();

        $this->report(['message' => $id])->assertCreated();
        $this->report(['message' => $id])->assertCreated();   // a second report on the same answer replaces the first
        $this->report(['message' => $id])->assertStatus(429)->assertJsonPath('message', 'That was a lot of reports. Try again later.');
    }

    public function test_the_app_hears_of_every_report(): void
    {
        Event::fake([AnswerReported::class]);
        $this->rekhu();
        $id = $this->ask();

        $this->report(['message' => $id, 'reason' => 'misunderstood', 'comment' => 'Not May 2025'])->assertCreated();

        Event::assertDispatched(AnswerReported::class, fn (AnswerReported $e) => $e->user?->getAuthIdentifier() === 7
            && $e->visitor === null && $e->conversation === self::CONVERSATION && $e->message === 'm_2'
            && $e->reason === 'misunderstood' && $e->comment === 'Not May 2025' && $e->report['answer'] === 'Revenue in May was 38,000.');
    }

    public function test_the_user_is_thanked_when_rekhu_cannot_take_the_report_and_it_is_tried_once_more(): void
    {
        $this->rekhu(status: 503);
        $id = $this->ask();

        $this->report(['message' => $id])->assertCreated()->assertJsonPath('message', 'Thanks, we will look into it.');

        Http::assertSentCount(3);   // the answer, the report and its second try
    }

    public function test_a_visitor_reports_an_answer_of_the_public_chat(): void
    {
        $this->rekhu('We open at nine.');
        $headers = $this->headers(['X-Rekhu-Visitor' => self::VISITOR]);
        $stream = $this->post('/rekhu/assistant/visitor/chat', ['message' => 'When do you open?', 'conversation' => self::CONVERSATION], $headers)->streamedContent();
        $id = collect($this->events($stream))->firstWhere('type', 'done')['message'];

        $this->postJson('/rekhu/assistant/visitor/chat/report', ['conversation' => self::CONVERSATION, 'message' => $id, 'reason' => 'wrong_information'], ['X-Rekhu-Protocol' => '1', 'X-Rekhu-Visitor' => self::VISITOR])
            ->assertCreated();
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/v1/reports') && $r->header('x-tenant')[0] === 'visitors' && str_starts_with($r->header('x-user')[0], 'visitor-'));

        // another visitor cannot report it
        $this->postJson('/rekhu/assistant/visitor/chat/report', ['conversation' => self::CONVERSATION, 'message' => $id, 'reason' => 'other'], ['X-Rekhu-Protocol' => '1', 'X-Rekhu-Visitor' => '1c9a6a3e-6f8f-4c4e-9a52-2f5d3c1e7a11'])
            ->assertNotFound();
    }

    public function test_the_actions_of_an_answer_are_reported_with_their_input_and_not_their_result(): void
    {
        $conversation = Conversation::open($this->user(), self::CONVERSATION);
        $conversation->messages = [
            ['role' => 'user', 'content' => 'Revenue in May?', 'id' => $conversation->nextId()],
        ];
        $answer = $conversation->nextId();
        $conversation->messages[] = ['role' => 'assistant', 'id' => $answer, 'content' => [
            ['type' => 'text', 'text' => 'Let me look.'],
            ['type' => 'tool_use', 'id' => 'tu_1', 'name' => 'revenue', 'input' => ['month' => '2026-05']],
            ['type' => 'tool_use', 'id' => 'tu_2', 'name' => 'payments', 'input' => ['month' => '2026-05']],
        ]];
        $conversation->messages[] = ['role' => 'user', 'content' => [
            ['type' => 'tool_result', 'tool_use_id' => 'tu_1', 'content' => '{"total":38000,"parent":"Mona, 0501234567"}'],
            ['type' => 'tool_result', 'tool_use_id' => 'tu_2', 'content' => '{"error":"forbidden"}', 'is_error' => true],
        ]];
        $conversation->messages[] = ['role' => 'assistant', 'id' => $answer, 'content' => [['type' => 'text', 'text' => 'It was 38,000.']]];
        $conversation->save();
        Http::fake(['rekhu.test/v1/reports' => Http::response(['report' => ['id' => 1]], 201)]);

        // an answer in two parts around its actions is one answer
        $this->actingAs($this->user())->getJson('/rekhu/assistant/chat?conversation='.self::CONVERSATION)
            ->assertJsonPath('messages.1', ['id' => 'm_2', 'role' => 'bot', 'text' => "Let me look.\n\nIt was 38,000."]);

        $this->report(['message' => 'm_2'])->assertCreated();
        Http::assertSent(fn (Request $r) => $r['answer'] === "Let me look.\n\nIt was 38,000."
            && $r['tools'] === [
                ['name' => 'revenue', 'input' => ['month' => '2026-05'], 'ok' => true],
                ['name' => 'payments', 'input' => ['month' => '2026-05'], 'ok' => false, 'error' => 'forbidden'],
            ]
            && ! str_contains(json_encode($r->data()), '0501234567'));

        // an app that opts in sends the results too, cut short
        config(['rekhu-assistant.reports.include_results' => true]);
        $this->report(['message' => 'm_2', 'reason' => 'other'])->assertCreated();
        Http::assertSent(fn (Request $r) => ($r['tools'][0]['result'] ?? null) === '{"total":38000,"parent":"Mona, 0501234567"}');
    }

    public function test_ids_stay_the_same_when_the_oldest_turns_are_trimmed(): void
    {
        $conversation = Conversation::open($this->user(), self::CONVERSATION);
        for ($i = 0; $i < 40; $i++) {
            $conversation->messages[] = ['role' => 'user', 'content' => "Question $i", 'id' => $conversation->nextId()];
            $conversation->messages[] = ['role' => 'assistant', 'content' => [['type' => 'text', 'text' => "Answer $i"]], 'id' => $conversation->nextId()];
        }
        $conversation->save();

        $kept = Conversation::open($this->user(), self::CONVERSATION);
        $lines = $kept->transcript();
        $this->assertSame(['id' => 'm_80', 'role' => 'bot', 'text' => 'Answer 39'], end($lines));
        $this->assertSame('m_81', $kept->nextId());
        $this->assertNotNull($kept->answer('m_80'));
    }

    public function test_a_conversation_stored_before_ids_gets_them_when_opened(): void
    {
        $conversation = Conversation::open($this->user(), self::CONVERSATION);
        $conversation->messages = [
            ['role' => 'user', 'content' => 'Hi'],
            ['role' => 'assistant', 'content' => [['type' => 'text', 'text' => 'Hello.']]],
        ];
        // as an older version stored it: no ids and no counter
        cache()->put('rekhu-assistant:'.$conversation->ownerKey().':'.self::CONVERSATION, ['messages' => $conversation->messages, 'pending' => []]);

        $this->assertSame([['id' => 'm_1', 'role' => 'user', 'text' => 'Hi'], ['id' => 'm_2', 'role' => 'bot', 'text' => 'Hello.']],
            Conversation::open($this->user(), self::CONVERSATION)->transcript());
    }

    public function test_reports_turned_off_are_not_offered(): void
    {
        config(['rekhu-assistant.reports.enabled' => false]);
        $this->rekhu();
        $this->ask();

        $this->actingAs($this->user())->getJson('/rekhu/assistant/chat?conversation='.self::CONVERSATION)->assertJsonMissingPath('report');
    }

    public function test_rekhu_assistant_says_whether_reports_work(): void
    {
        Http::fake(['rekhu.test/v1/balance' => Http::response(['balance_usd' => 5]), 'rekhu.test/v1/reports' => Http::response(['reports' => true])]);
        $this->artisan('rekhu:assistant')->expectsOutputToContain('Reports on: wrong_answer, misunderstood, wrong_information, other')
            ->expectsOutputToContain('Rekhu takes reports')->assertSuccessful();
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/v1/reports') && $r['check'] === true);
    }

    public function test_rekhu_assistant_counts_only_a_success_as_reports_taken(): void
    {
        Http::fake(['rekhu.test/v1/balance' => Http::response(['balance_usd' => 5]), 'rekhu.test/v1/reports' => Http::response(['error' => ['message' => 'reason is a short key']], 422)]);
        $this->artisan('rekhu:assistant')->doesntExpectOutputToContain('Rekhu takes reports')
            ->expectsOutputToContain('Rekhu refused the report check (422): reason is a short key')->assertSuccessful();
    }

    public function test_rekhu_assistant_says_when_the_platform_takes_no_reports_yet(): void
    {
        Http::fake(['rekhu.test/v1/balance' => Http::response(['balance_usd' => 5]), 'rekhu.test/v1/reports' => Http::response(['error' => ['message' => 'Not found']], 404)]);
        $this->artisan('rekhu:assistant')->expectsOutputToContain('The platform does not accept reports yet')->assertSuccessful();
    }

    public function test_an_actions_empty_input_stays_an_object_whatever_the_cache_store_unserializes(): void
    {
        $conversation = Conversation::open($this->user(), self::CONVERSATION);
        $conversation->messages = [
            ['role' => 'user', 'content' => 'How many orders?', 'id' => $conversation->nextId()],
            ['role' => 'assistant', 'id' => $conversation->nextId(), 'content' => [['type' => 'tool_use', 'id' => 'tu_1', 'name' => 'list_orders', 'input' => (object) []]]],
            ['role' => 'user', 'content' => [['type' => 'tool_result', 'tool_use_id' => 'tu_1', 'content' => '[]']]],
            ['role' => 'assistant', 'id' => 'm_2', 'content' => [['type' => 'text', 'text' => 'You have 8 orders.']]],
        ];
        $conversation->save();
        $this->assertIsString(cache()->get('rekhu-assistant:'.$conversation->ownerKey().':'.self::CONVERSATION), 'kept as JSON');

        $kept = Conversation::open($this->user(), self::CONVERSATION);
        $this->assertSame('{}', json_encode($kept->forModel()[1]['content'][0]['input']));
        $this->assertSame('{}', json_encode($kept->answer('m_2')['tools'][0]['input']));

        // as a store that unserializes no classes returns an object an earlier version kept
        $broken = unserialize(serialize(['messages' => $conversation->messages, 'pending' => [], 'next' => 3]), ['allowed_classes' => false]);
        cache()->put('rekhu-assistant:'.$conversation->ownerKey().':'.self::CONVERSATION, $broken);
        $this->assertSame('{}', json_encode(Conversation::open($this->user(), self::CONVERSATION)->forModel()[1]['content'][0]['input']));
    }
}
