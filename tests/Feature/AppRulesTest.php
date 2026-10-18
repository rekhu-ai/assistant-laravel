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
        $router->get('/api/orders', [OrdersController::class, 'index'])->name('api.orders.list');
        $router->post('/api/orders', [OrdersController::class, 'store'])->name('api.orders.create');
    }

    public function test_the_apps_own_rule_decides_which_actions_a_user_gets(): void
    {
        $this->assertSame(['api_orders_list', 'api_orders_create'], array_keys(Actions::for($this->user())));

        // the app checks rights by route name, as with Laratrust or Spatie permissions
        RekhuAssistant::authorizeUsing(fn ($user, $route) => $route->getName() === 'api.orders.list');

        $this->assertSame(['api_orders_list'], array_keys(Actions::for($this->user())));
        $this->assertSame(['api_orders_list', 'api_orders_create'], array_keys(Actions::for($this->user(), all: true)));
    }

    public function test_the_config_adds_routes_as_actions_and_blocks_others(): void
    {
        Route::get('/api/invoices/{invoice}/pdf', fn () => 'pdf')->name('api.invoices.pdf');

        config(['rekhu-assistant.actions.include' => [
            'api.invoices.pdf' => 'Download an invoice as PDF',
            'POST api/orders' => ['description' => 'Place an order for the user', 'name' => 'place_order', 'confirm' => true],
        ]]);
        $actions = Actions::for($this->user());
        $this->assertSame('attribute', $actions['api_orders_list']['source']);
        $this->assertSame('config', $actions['api_invoices_pdf']['source']);
        $this->assertSame('Place an order for the user', $actions['place_order']['description']);
        $this->assertTrue($actions['place_order']['confirm']);

        config(['rekhu-assistant.actions.exclude' => ['api.orders.*']]);
        $this->assertSame(['api_invoices_pdf'], array_keys(Actions::for($this->user())));
    }

    public function test_front_end_pages_come_from_the_config_and_from_code(): void
    {
        config(['rekhu-assistant.pages.frontend.list' => [
            ['path' => '/dashboard/orders', 'label' => 'Orders', 'help' => 'All orders'],
            ['path' => '/dashboard/finance', 'label' => 'Finance', 'can' => 'see-finance'],
        ]]);
        RekhuAssistant::pagesUsing(fn ($user) => $user->id === 7 ? [['path' => '/dashboard/customers/{customer}', 'label' => 'Customer']] : []);

        $pages = Pages::for($this->user(7));

        $this->assertSame(['dashboard.orders', 'dashboard.customers'], array_keys($pages));
        $this->assertSame('/dashboard/customers/{customer}', $pages['dashboard.customers']['uri']);
        $this->assertSame(['customer'], $pages['dashboard.customers']['parameters']);
        $this->assertArrayNotHasKey('dashboard.customers', Pages::for($this->user(8)));
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
        RekhuAssistant::pagesUsing(fn () => [['path' => '/dashboard/customers/{customer}', 'label' => 'Customer']]);
        $this->rekhuCalls('open_page', ['page' => 'dashboard.customers', 'parameters' => ['customer' => 12]], 'Here it is.');

        $events = $this->events($this->actingAs($this->user())->post('/rekhu/assistant/chat', ['message' => 'Open customer 12'], $this->headers())->streamedContent());

        $card = collect($events)->firstWhere('type', 'card');
        $this->assertSame('https://app.example.com/dashboard/customers/12', $card['url']);
        Http::assertSent(fn (Request $r) => str_contains(str_replace('\\/', '/', $r->body()), 'dashboard.customers: /dashboard/customers/{customer}'));
    }

    public function test_a_fresh_chat_gets_the_quick_questions_this_user_may_ask(): void
    {
        config(['rekhu-assistant.quick' => [
            'Where is my last order?',
            ['text' => ['en' => 'Show my orders', 'ar' => 'اعرض طلباتي'], 'needs' => ['api_orders_list']],
            ['text' => 'Place an order', 'needs' => ['api_orders_create']],
        ]]);
        RekhuAssistant::authorizeUsing(fn ($user, $route) => $route->getName() === 'api.orders.list');

        $this->actingAs($this->user())->getJson('/rekhu/assistant/chat')->assertJsonPath('quick', ['Where is my last order?', 'Show my orders']);
        app()->setLocale('ar');
        $this->actingAs($this->user())->getJson('/rekhu/assistant/chat')->assertJsonPath('quick.1', 'اعرض طلباتي');
        $this->actingAs($this->user())->getJson('/rekhu/assistant/chat?conversation=conv-12345678')->assertJsonMissingPath('quick');
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

class OrdersController
{
    #[Tool('List the orders')]
    public function index(): array
    {
        return [];
    }

    #[Tool('Place an order')]
    public function store(): array
    {
        return [];
    }
}
