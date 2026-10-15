<?php

declare(strict_types=1);

namespace Rekhu\Assistant\Tests\Feature;

use Rekhu\Assistant\Tests\TestCase;

final class GuestsOffTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('rekhu-assistant.guests.enabled', false);
    }

    public function test_there_is_no_public_chat_until_the_app_turns_it_on(): void
    {
        $this->post('/rekhu/assistant/visitor/chat', ['message' => 'Hi'], $this->headers(['X-Rekhu-Visitor' => self::VISITOR]))
            ->assertNotFound();
    }
}
