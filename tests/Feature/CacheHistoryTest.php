<?php

declare(strict_types=1);

namespace Rekhu\Assistant\Tests\Feature;

use Rekhu\Assistant\RekhuAssistant;
use Rekhu\Assistant\Runtime\CacheConversations;
use Rekhu\Assistant\Tests\TestCase;

/** The list of earlier conversations with the cache store, for an app that has not run the package's migration. */
final class CacheHistoryTest extends TestCase
{
    use HistoryTests;

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('rekhu-assistant.conversations.store', 'cache');
        RekhuAssistant::conversationsUsing(null);
    }

    public function test_the_cache_store_is_used_without_the_table(): void
    {
        $this->assertInstanceOf(CacheConversations::class, RekhuAssistant::store());
    }
}
