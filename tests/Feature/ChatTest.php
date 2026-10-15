<?php

declare(strict_types=1);

namespace Rekhu\Assistant\Tests\Feature;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Rekhu\Assistant\Runtime\Conversation;
use Rekhu\Assistant\Tests\TestCase;

final class ChatTest extends TestCase
{
    public function test_a_message_streams_the_answer(): void
    {
        $this->rekhuAnswers('Hello Ada.');

        $response = $this->actingAs($this->user())->post('/rekhu/assistant/chat', ['message' => 'Hi'], $this->headers());

        $response->assertOk()->assertHeader('Content-Type', 'text/event-stream; charset=UTF-8');
        $events = $this->events($response->streamedContent());
        $this->assertSame(['start', 'text', 'done'], array_column($events, 'type'));
        $this->assertSame(1, $events[0]['protocol']);
        $this->assertSame('Hello Ada.', $events[1]['text']);
    }

    public function test_rekhu_is_told_the_user_and_the_conversation_but_no_name(): void
    {
        $this->rekhuAnswers('Hi.');

        $this->actingAs($this->user(42))->post('/rekhu/assistant/chat', ['message' => 'Hi', 'conversation' => 'conv-12345678'], $this->headers())->streamedContent();

        Http::assertSent(fn (Request $r) => $r->url() === 'https://rekhu.test/v1/messages'
            && $r->header('x-api-key')[0] === self::KEY
            && $r->header('x-user')[0] === '42'
            && $r->header('x-session')[0] === 'assistant-conv-12345678');
    }

    public function test_the_history_shows_what_the_user_saw(): void
    {
        $this->rekhuAnswers('Your order ships tomorrow.');
        $this->actingAs($this->user())->post('/rekhu/assistant/chat', ['message' => 'Where is my order?', 'conversation' => 'conv-12345678'], $this->headers())->streamedContent();

        $this->actingAs($this->user())->getJson('/rekhu/assistant/chat?conversation=conv-12345678')
            ->assertOk()
            ->assertExactJson(['conversation' => 'conv-12345678', 'messages' => [
                ['role' => 'user', 'text' => 'Where is my order?'],
                ['role' => 'bot', 'text' => 'Your order ships tomorrow.'],
            ]]);
    }

    public function test_what_the_app_reports_for_the_user_stays_out_of_the_history(): void
    {
        $conversation = Conversation::open($this->user(), 'conv-12345678');
        $conversation->messages[] = ['role' => 'user', 'content' => 'Cancel order 12'];
        $conversation->report('I confirmed "Cancel an order". It ran.');
        $conversation->save();

        $this->actingAs($this->user())->getJson('/rekhu/assistant/chat?conversation=conv-12345678')
            ->assertJsonPath('messages', [['role' => 'user', 'text' => 'Cancel order 12']]);
    }

    public function test_a_conversation_belongs_to_its_user(): void
    {
        $this->rekhuAnswers('Private answer.');
        $this->actingAs($this->user(1))->post('/rekhu/assistant/chat', ['message' => 'Mine', 'conversation' => 'conv-12345678'], $this->headers())->streamedContent();

        $this->actingAs($this->user(2))->getJson('/rekhu/assistant/chat?conversation=conv-12345678')->assertJsonPath('messages', []);
    }

    public function test_forgetting_starts_over(): void
    {
        $this->rekhuAnswers('Noted.');
        $this->actingAs($this->user())->post('/rekhu/assistant/chat', ['message' => 'Remember this', 'conversation' => 'conv-12345678'], $this->headers())->streamedContent();

        $this->actingAs($this->user())->deleteJson('/rekhu/assistant/chat', ['conversation' => 'conv-12345678'], $this->headers())->assertNoContent();
        $this->actingAs($this->user())->getJson('/rekhu/assistant/chat?conversation=conv-12345678')->assertJsonPath('messages', []);
    }

    public function test_an_empty_message_is_refused_as_json(): void
    {
        $this->actingAs($this->user())->post('/rekhu/assistant/chat', ['message' => ''], $this->headers())
            ->assertStatus(422)
            ->assertJsonPath('message', 'Say something first.');
    }

    public function test_a_message_over_the_limit_is_refused(): void
    {
        config(['rekhu-assistant.max_message' => 10]);

        $this->actingAs($this->user())->post('/rekhu/assistant/chat', ['message' => str_repeat('a', 11)], $this->headers())
            ->assertStatus(422)
            ->assertJsonValidationErrors('message');
    }

    public function test_another_protocol_version_is_refused(): void
    {
        $this->actingAs($this->user())->post('/rekhu/assistant/chat', ['message' => 'Hi'], $this->headers(['X-Rekhu-Protocol' => '2']))
            ->assertStatus(400)
            ->assertJsonPath('message', 'This app speaks Rekhu Assistant protocol 1.');
    }

    public function test_the_chat_needs_a_signed_in_user(): void
    {
        $this->post('/rekhu/assistant/chat', ['message' => 'Hi'], $this->headers())
            ->assertUnauthorized()
            ->assertJsonPath('message', 'Unauthenticated.');
    }

    public function test_a_paused_project_tells_the_user_little_and_the_log_why(): void
    {
        Log::spy();
        $this->rekhuRefuses(402, 'app_limit');

        $events = $this->events($this->actingAs($this->user())->post('/rekhu/assistant/chat', ['message' => 'Hi'], $this->headers())->streamedContent());

        $this->assertSame(['start', 'error'], array_column($events, 'type'));
        $this->assertSame('The assistant is not available right now. Please try again in a moment.', $events[1]['message']);
        Log::shouldHaveReceived('warning')->withArgs(fn ($message) => str_contains($message, 'paused or at its monthly limit'))->once();
    }

    public function test_used_up_credit_is_logged_as_an_error(): void
    {
        Log::spy();
        $this->rekhuRefuses(402, 'billing_error');

        $this->actingAs($this->user())->post('/rekhu/assistant/chat', ['message' => 'Hi'], $this->headers())->streamedContent();

        Log::shouldHaveReceived('error')->withArgs(fn ($message) => str_contains($message, 'credit of your Rekhu account is used up'))->once();
    }

    public function test_the_user_messages_can_be_translated(): void
    {
        app('translator')->addLines(['messages.empty' => 'Zeg eerst iets.'], 'nl', 'rekhu-assistant');
        app()->setLocale('nl');

        $this->actingAs($this->user())->post('/rekhu/assistant/chat', ['message' => ''], $this->headers())
            ->assertJsonPath('message', 'Zeg eerst iets.');
    }
}
