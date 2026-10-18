<?php

declare(strict_types=1);

namespace Rekhu\Assistant\Tests\Feature;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Rekhu\Assistant\Attributes\Tool;
use Rekhu\Assistant\Catalogue\Actions;
use Rekhu\Assistant\Catalogue\Pages;
use Rekhu\Assistant\RekhuAssistant;
use Rekhu\Assistant\Tests\TestCase;

final class AppRulesTest extends TestCase
{
    protected function tearDown(): void
    {
        RekhuAssistant::authorizeUsing(null);
        RekhuAssistant::pagesUsing(null);
        parent::tearDown();
    }

    protected function defineRoutes($router): void
    {
        $router->get('/api/branches', [BranchesController::class, 'index'])->name('v2.mgr.branches-list');
        $router->post('/api/branches', [BranchesController::class, 'store'])->name('v2.mgr.branches-create');
    }

    public function test_the_apps_own_rule_decides_which_actions_a_user_gets(): void
    {
        $this->assertSame(['v2_mgr_branches-list', 'v2_mgr_branches-create'], array_keys(Actions::for($this->user())));

        // the app checks rights by route name, as with Laratrust permissions
        RekhuAssistant::authorizeUsing(fn ($user, $route) => $route->getName() === 'v2.mgr.branches-list');

        $this->assertSame(['v2_mgr_branches-list'], array_keys(Actions::for($this->user())));
        $this->assertSame(['v2_mgr_branches-list', 'v2_mgr_branches-create'], array_keys(Actions::for($this->user(), all: true)));
    }

    public function test_front_end_pages_come_from_the_config_and_from_code(): void
    {
        config(['rekhu-assistant.pages.frontend.list' => [
            ['path' => '/dashboard/branches', 'label' => 'Branches', 'help' => 'All branches'],
            ['path' => '/dashboard/finance', 'label' => 'Finance', 'can' => 'see-finance'],
        ]]);
        RekhuAssistant::pagesUsing(fn ($user) => $user->id === 7 ? [['path' => '/dashboard/children/{child}', 'label' => 'Child']] : []);

        $pages = Pages::for($this->user(7));

        $this->assertSame(['dashboard.branches', 'dashboard.children'], array_keys($pages));
        $this->assertSame('/dashboard/children/{child}', $pages['dashboard.children']['uri']);
        $this->assertSame(['child'], $pages['dashboard.children']['parameters']);
        $this->assertArrayNotHasKey('dashboard.children', Pages::for($this->user(8)));
    }

    public function test_an_api_offers_only_its_front_ends_pages(): void
    {
        Route::get('/invitations/{hash}', fn () => 'ok')->name('invitation-page');
        RekhuAssistant::pagesUsing(fn ($user) => ! $user ? [] : [['path' => '/dashboard', 'label' => 'Home']]);

        $this->assertContains('invitation-page', array_keys(Pages::for($this->user())));
        $this->assertSame([], array_filter(Pages::for(null), fn ($page) => ! empty($page['frontend'])));

        config(['rekhu-assistant.pages.routes' => false]);
        $this->assertSame(['dashboard'], array_keys(Pages::for($this->user())));
    }

    public function test_a_front_end_page_opens_at_the_front_ends_address(): void
    {
        config(['rekhu-assistant.pages.frontend.url' => 'https://app.example.com/']);
        RekhuAssistant::pagesUsing(fn () => [['path' => '/dashboard/children/{child}', 'label' => 'Child']]);
        $this->rekhuCalls('open_page', ['page' => 'dashboard.children', 'parameters' => ['child' => 12]], 'Here it is.');

        $events = $this->events($this->actingAs($this->user())->post('/rekhu/assistant/chat', ['message' => 'Open child 12'], $this->headers())->streamedContent());

        $card = collect($events)->firstWhere('type', 'card');
        $this->assertSame('https://app.example.com/dashboard/children/12', $card['url']);
        Http::assertSent(fn (Request $r) => str_contains(str_replace('\\/', '/', $r->body()), 'dashboard.children: /dashboard/children/{child}'));
    }

    /** Rekhu's first answer calls one tool, its second answers in text. */
    private function rekhuCalls(string $tool, array $input, string $text): void
    {
        $stream = fn (array $events) => implode('', array_map(fn ($e) => 'event: '.$e['type']."\ndata: ".json_encode($e)."\n\n", $events));
        $call = $stream([
            ['type' => 'message_start', 'message' => ['content' => []]],
            ['type' => 'content_block_start', 'index' => 0, 'content_block' => ['type' => 'tool_use', 'id' => 'tu_1', 'name' => $tool, 'input' => (object) []]],
            ['type' => 'content_block_delta', 'index' => 0, 'delta' => ['type' => 'input_json_delta', 'partial_json' => json_encode($input)]],
            ['type' => 'content_block_stop', 'index' => 0],
            ['type' => 'message_delta', 'delta' => ['stop_reason' => 'tool_use']],
            ['type' => 'message_stop'],
        ]);
        $answer = $stream([
            ['type' => 'message_start', 'message' => ['content' => []]],
            ['type' => 'content_block_start', 'index' => 0, 'content_block' => ['type' => 'text', 'text' => '']],
            ['type' => 'content_block_delta', 'index' => 0, 'delta' => ['type' => 'text_delta', 'text' => $text]],
            ['type' => 'content_block_stop', 'index' => 0],
            ['type' => 'message_delta', 'delta' => ['stop_reason' => 'end_turn']],
            ['type' => 'message_stop'],
        ]);
        Http::fake(['rekhu.test/*' => Http::sequence()
            ->push($call, 200, ['Content-Type' => 'text/event-stream'])
            ->push($answer, 200, ['Content-Type' => 'text/event-stream'])]);
    }
}

class BranchesController
{
    #[Tool('List the branches')]
    public function index(): array
    {
        return [];
    }

    #[Tool('Add a branch')]
    public function store(): array
    {
        return [];
    }
}
