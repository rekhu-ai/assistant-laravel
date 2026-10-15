<?php

declare(strict_types=1);

namespace Rekhu\Assistant\Tests;

use Illuminate\Auth\GenericUser;
use Illuminate\Support\Facades\Http;
use Orchestra\Testbench\TestCase as Orchestra;
use Rekhu\Assistant\AssistantServiceProvider;

abstract class TestCase extends Orchestra
{
    protected const KEY = 'agk_acme_assistant_testsecret';

    protected const VISITOR = '0b9a6a3e-6f8f-4c4e-9a52-2f5d3c1e7a10';

    protected function getPackageProviders($app): array
    {
        return [AssistantServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('a', 32)));
        $app['config']->set('rekhu-assistant.key', self::KEY);
        $app['config']->set('rekhu-assistant.platform', 'https://rekhu.test');
        $app['config']->set('rekhu-assistant.guests.enabled', true);
    }

    protected function user(int $id = 7): GenericUser
    {
        return new GenericUser(['id' => $id, 'name' => 'Ada']);
    }

    /** Rekhu answers every model call with this text, streamed. */
    protected function rekhuAnswers(string $text): void
    {
        $events = [
            ['type' => 'message_start', 'message' => ['content' => []]],
            ['type' => 'content_block_start', 'index' => 0, 'content_block' => ['type' => 'text', 'text' => '']],
            ['type' => 'content_block_delta', 'index' => 0, 'delta' => ['type' => 'text_delta', 'text' => $text]],
            ['type' => 'content_block_stop', 'index' => 0],
            ['type' => 'message_delta', 'delta' => ['stop_reason' => 'end_turn']],
            ['type' => 'message_stop'],
        ];
        $body = implode('', array_map(fn ($e) => 'event: '.$e['type']."\ndata: ".json_encode($e)."\n\n", $events));
        Http::fake(['rekhu.test/*' => Http::response($body, 200, ['Content-Type' => 'text/event-stream'])]);
    }

    /** Rekhu refuses every model call. */
    protected function rekhuRefuses(int $status, string $type): void
    {
        Http::fake(['rekhu.test/*' => Http::response(['error' => ['type' => $type, 'message' => 'refused for the test']], $status)]);
    }

    /** @return list<array<string, mixed>> the events of a streamed answer */
    protected function events(string $stream): array
    {
        preg_match_all('/^data: (.+)$/m', $stream, $m);

        return array_map(fn ($json) => json_decode($json, true), $m[1]);
    }

    protected function headers(array $extra = []): array
    {
        return array_merge(['Accept' => 'text/event-stream', 'X-Rekhu-Protocol' => '1'], $extra);
    }
}
