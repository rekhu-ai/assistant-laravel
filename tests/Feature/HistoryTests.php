<?php

declare(strict_types=1);

namespace Rekhu\Assistant\Tests\Feature;

/**
 * The chat's list of earlier conversations, the same for every store: listed newest first with their question count,
 * paged, searched, renamed and deleted by id, and only ever the user's own.
 */
trait HistoryTests
{
    /** One turn of the user in a conversation. */
    private function ask(string $conversation, string $message, int $user = 7): void
    {
        $this->rekhuAnswers('Noted.');
        $this->actingAs($this->user($user))->post('/rekhu/assistant/chat', ['message' => $message, 'conversation' => $conversation], $this->headers())->streamedContent();
        $this->travel(1)->seconds();
    }

    public function test_conversations_are_listed_newest_first_with_their_questions(): void
    {
        $this->ask('conv-aaaaaaaa', 'Where is my last order?');
        $this->ask('conv-aaaaaaaa', 'And the one before?');
        $this->ask('conv-bbbbbbbb', 'Show my invoices');

        $list = $this->actingAs($this->user())->getJson('/rekhu/assistant/chat/conversations')->assertOk()->json('conversations');

        $this->assertSame(['conv-bbbbbbbb', 'conv-aaaaaaaa'], array_column($list, 'id'));
        $this->assertSame([1, 2], array_column($list, 'questions'));
        $this->assertSame('Where is my last order?', $list[1]['title']);
    }

    public function test_the_list_comes_in_pages(): void
    {
        foreach (range(1, 5) as $i) {
            $this->ask("conv-page000{$i}", "Question {$i}");
        }

        $first = $this->actingAs($this->user())->getJson('/rekhu/assistant/chat/conversations?limit=2')->assertOk();
        $this->assertSame(['conv-page0005', 'conv-page0004'], array_column($first->json('conversations'), 'id'));
        $second = $this->actingAs($this->user())->getJson('/rekhu/assistant/chat/conversations?limit=2&before='.urlencode($first->json('next')))->assertOk();
        $this->assertSame(['conv-page0003', 'conv-page0002'], array_column($second->json('conversations'), 'id'));
        $third = $this->actingAs($this->user())->getJson('/rekhu/assistant/chat/conversations?limit=2&before='.urlencode($second->json('next')))->assertOk();
        $this->assertSame(['conv-page0001'], array_column($third->json('conversations'), 'id'));
        $this->assertNull($third->json('next'));
    }

    public function test_the_search_finds_titles_and_questions_ignoring_case(): void
    {
        $this->ask('conv-search01', 'Where is my order?');
        $this->ask('conv-search01', 'It was a RED bicycle');
        $this->ask('conv-search02', 'Show my invoices');

        $found = fn (string $q) => array_column($this->actingAs($this->user())->getJson('/rekhu/assistant/chat/conversations?q='.urlencode($q))->json('conversations'), 'id');

        $this->assertSame(['conv-search01'], $found('red bicycle'));
        $this->assertSame(['conv-search02'], $found('INVOICES'));
        $this->assertSame([], $found('nothing like this'));
    }

    public function test_a_conversation_is_renamed_and_named_back(): void
    {
        $this->ask('conv-rename01', 'Where is my order?');

        $this->actingAs($this->user())->patchJson('/rekhu/assistant/chat/conversations/conv-rename01', ['title' => 'Order questions'], $this->headers())
            ->assertOk()->assertJsonPath('conversation.title', 'Order questions');
        $this->assertSame('Order questions', $this->actingAs($this->user())->getJson('/rekhu/assistant/chat/conversations')->json('conversations.0.title'));

        $this->ask('conv-rename01', 'One more');
        $this->assertSame('Order questions', $this->actingAs($this->user())->getJson('/rekhu/assistant/chat/conversations')->json('conversations.0.title'));

        $this->actingAs($this->user())->patchJson('/rekhu/assistant/chat/conversations/conv-rename01', ['title' => ''], $this->headers())
            ->assertOk()->assertJsonPath('conversation.title', 'Where is my order?');
    }

    public function test_a_conversation_is_deleted_by_id(): void
    {
        $this->ask('conv-delete01', 'Forget this');
        $this->ask('conv-delete02', 'Keep this');

        $this->actingAs($this->user())->deleteJson('/rekhu/assistant/chat/conversations/conv-delete01', [], $this->headers())->assertNoContent();

        $this->assertSame(['conv-delete02'], array_column($this->actingAs($this->user())->getJson('/rekhu/assistant/chat/conversations')->json('conversations'), 'id'));
        $this->actingAs($this->user())->getJson('/rekhu/assistant/chat?conversation=conv-delete01')->assertJsonPath('messages', []);
    }

    public function test_another_users_conversations_are_never_listed_renamed_or_deleted(): void
    {
        $this->ask('conv-owner001', 'Mine', 1);

        $this->assertSame([], $this->actingAs($this->user(2))->getJson('/rekhu/assistant/chat/conversations')->json('conversations'));
        $this->actingAs($this->user(2))->patchJson('/rekhu/assistant/chat/conversations/conv-owner001', ['title' => 'Taken'], $this->headers())->assertNotFound();
        $this->actingAs($this->user(2))->deleteJson('/rekhu/assistant/chat/conversations/conv-owner001', [], $this->headers())->assertNoContent();

        $this->assertSame(['Mine'], array_column($this->actingAs($this->user(1))->getJson('/rekhu/assistant/chat/conversations')->json('conversations'), 'title'));
    }

    public function test_the_chat_says_a_signed_in_user_has_a_list_and_a_visitor_has_none(): void
    {
        $this->actingAs($this->user())->getJson('/rekhu/assistant/chat')->assertJsonPath('history', true);
        $this->getJson('/rekhu/assistant/visitor/chat', ['X-Rekhu-Visitor' => self::VISITOR])->assertJsonMissingPath('history');
    }
}
