<?php

declare(strict_types=1);

namespace Rekhu\Assistant\Tests\Feature;

use Rekhu\Assistant\RekhuAssistant;
use Rekhu\Assistant\Runtime\DatabaseConversations;
use Rekhu\Assistant\Tests\TestCase;

/** The list of earlier conversations with the database store, chosen by itself once the migration has run. */
final class DatabaseHistoryTest extends TestCase
{
    use HistoryTests;

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../../database/migrations');
        RekhuAssistant::conversationsUsing(null);
    }

    public function test_the_database_store_is_chosen_once_the_table_exists(): void
    {
        $this->assertInstanceOf(DatabaseConversations::class, RekhuAssistant::store());
    }

    public function test_conversations_past_their_days_are_pruned(): void
    {
        config(['rekhu-assistant.conversations.keep_days' => 1]);
        $this->ask('conv-prune001', 'Old question');
        $this->travel(2)->days();
        $this->ask('conv-prune002', 'New question');

        $this->artisan('rekhu-assistant:prune')->expectsOutput('1 conversation removed.')->assertSuccessful();

        $this->assertSame(['conv-prune002'], array_column($this->actingAs($this->user())->getJson('/rekhu/assistant/chat/conversations')->json('conversations'), 'id'));
    }

    public function test_the_question_count_goes_on_after_old_turns_are_dropped(): void
    {
        foreach (range(1, 35) as $i) {
            $this->ask('conv-longlong', "Question {$i}");
            if ($i % 20 === 0) {
                $this->travel(2)->minutes();
            }
        }

        $this->assertSame(35, $this->actingAs($this->user())->getJson('/rekhu/assistant/chat/conversations')->json('conversations.0.questions'));
    }
}
