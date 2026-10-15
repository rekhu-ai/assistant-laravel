<?php

declare(strict_types=1);

namespace Rekhu\Assistant\Tests\Feature;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Rekhu\Assistant\Tests\TestCase;

final class VisitorChatTest extends TestCase
{
    public function test_a_visitor_chats_without_signing_in(): void
    {
        $this->rekhuAnswers('Welcome!');

        $response = $this->post('/rekhu/assistant/visitor/chat', ['message' => 'Hi'], $this->headers(['X-Rekhu-Visitor' => self::VISITOR]));

        $this->assertSame(['start', 'text', 'done'], array_column($this->events($response->streamedContent()), 'type'));
        Http::assertSent(fn (Request $r) => str_starts_with($r->header('x-user')[0], 'visitor-') && $r->header('x-tenant')[0] === 'visitors');
    }

    public function test_a_signed_in_user_on_the_public_chat_is_a_visitor_there(): void
    {
        $this->rekhuAnswers('Welcome!');

        $this->actingAs($this->user(42))->post('/rekhu/assistant/visitor/chat', ['message' => 'Hi'], $this->headers(['X-Rekhu-Visitor' => self::VISITOR]))->streamedContent();

        Http::assertSent(fn (Request $r) => $r->header('x-user')[0] !== '42' && str_starts_with($r->header('x-user')[0], 'visitor-'));
    }

    public function test_the_public_chat_needs_a_visitor_id(): void
    {
        $this->post('/rekhu/assistant/visitor/chat', ['message' => 'Hi'], $this->headers())
            ->assertStatus(422)
            ->assertJsonPath('message', 'The public chat needs a visitor id (X-Rekhu-Visitor).');
    }

    public function test_a_visitor_is_capped_per_day(): void
    {
        config(['rekhu-assistant.guests.per_visitor_per_day' => 1]);
        $this->rekhuAnswers('Welcome!');
        $headers = $this->headers(['X-Rekhu-Visitor' => self::VISITOR]);

        $this->post('/rekhu/assistant/visitor/chat', ['message' => 'Hi'], $headers)->assertOk()->streamedContent();

        $this->post('/rekhu/assistant/visitor/chat', ['message' => 'Again'], $headers)
            ->assertStatus(429)
            ->assertJsonPath('message', 'You have sent the most messages for today. Please come back tomorrow, or sign in.');
    }

    public function test_a_visitor_message_has_its_own_limit(): void
    {
        config(['rekhu-assistant.guests.max_message' => 5]);

        $this->post('/rekhu/assistant/visitor/chat', ['message' => 'Too long'], $this->headers(['X-Rekhu-Visitor' => self::VISITOR]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('message');
    }
}
