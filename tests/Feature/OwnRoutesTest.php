<?php

declare(strict_types=1);

namespace Rekhu\Assistant\Tests\Feature;

use Illuminate\Support\Facades\Route;
use Rekhu\Assistant\Catalogue\Pages;
use Rekhu\Assistant\RekhuAssistant;
use Rekhu\Assistant\Tests\TestCase;

final class OwnRoutesTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('rekhu-assistant.routes', false);
    }

    protected function defineRoutes($router): void
    {
        // the app's own route file: its own prefix and group around the assistant's routes
        $router->prefix('api/v2')->name('v2.')->group(fn () => RekhuAssistant::routes(['path' => 'assistant', 'middleware' => ['web', 'auth'], 'guests' => false]));
    }

    public function test_the_app_registers_the_routes_in_its_own_group(): void
    {
        $this->assertFalse(Route::has('rekhu.assistant.chat'), 'the package did not register its own');
        $this->assertTrue(Route::has('v2.rekhu.assistant.chat'));
        $this->assertSame('/api/v2/assistant/chat', route('v2.rekhu.assistant.chat', [], false));
        $this->assertSame('/api/v2/assistant/chat/report', route('v2.rekhu.assistant.report', [], false));
        $this->assertFalse(Route::has('v2.rekhu.assistant.visitor'));
        $this->assertSame([], array_filter(array_keys(Pages::for($this->user())), fn ($n) => str_contains($n, 'rekhu')));

        $this->rekhuAnswers('Hello from your route.');
        $events = $this->events($this->actingAs($this->user())->post('/api/v2/assistant/chat', ['message' => 'Hi'], $this->headers())->streamedContent());
        $this->assertSame('Hello from your route.', collect($events)->firstWhere('type', 'text')['text']);
    }
}
