<?php

declare(strict_types=1);

namespace Rekhu\Assistant\Tests\Feature;

use Illuminate\Auth\GenericUser;
use Illuminate\Http\Client\Request as Sent;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Rekhu\Assistant\Events\AnswerCompleted;
use Rekhu\Assistant\Jobs\SendReport;
use Rekhu\Assistant\RekhuAssistant;
use Rekhu\Assistant\Runtime\Conversation;
use Rekhu\Assistant\Tests\TestCase;
use Rekhu\Assistant\Tool;
use Rekhu\Assistant\Usage;

/**
 * A multi-tenant app with a users table per tenant (one gateway, many print shops): what keeps tenants apart, puts
 * later work back in the right tenant, and lets tools do several steps, show progress and report their own usage.
 */
final class MultiTenantTest extends TestCase
{
    private array $queue = [];

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('rekhu-assistant.debug', false);
        RekhuAssistant::tools([QuoteMarketplace::class]);
    }

    protected function setUp(): void
    {
        parent::setUp();
        RekhuAssistant::tenantUsing(fn ($user) => ['id' => $user->tenant, 'name' => 'Shop '.$user->tenant]);
        Http::fake(['rekhu.test/*' => fn () => Http::response(array_shift($this->queue) ?? '', 200, ['Content-Type' => 'text/event-stream'])]);
    }

    protected function tearDown(): void
    {
        RekhuAssistant::tenantUsing(null);
        RekhuAssistant::restoreTenantUsing(null);
        RekhuAssistant::enabledUsing(null);
        RekhuAssistant::brandUsing(null);
        parent::tearDown();
    }

    protected function defineRoutes($router): void
    {
        $router->middleware('web')->post('cart/price', fn (Request $r) => ['product' => $r->input('product'), 'quantity' => $r->integer('quantity'), 'price' => 89, 'tenant_user' => $r->user()?->getAuthIdentifier()])->name('cart.price');
        $router->middleware('web')->post('preflight/check', fn () => Usage::attach(['ok' => true], 'preflight', 1))->name('preflight.check');
    }

    private function in(string $tenant, int $id = 3): GenericUser
    {
        return new GenericUser(['id' => $id, 'name' => 'Sam', 'tenant' => $tenant]);
    }

    private function answers(string ...$streams): void
    {
        array_push($this->queue, ...$streams);
    }

    private function text(string $text): string
    {
        return $this->streamed([['type' => 'text', 'text' => $text]], 'end_turn');
    }

    private function streamed(array $blocks, string $stop): string
    {
        $events = [['type' => 'message_start', 'message' => ['content' => [], 'model' => 'Rekhu Standard', 'usage' => ['input_tokens' => 100]]]];
        foreach ($blocks as $i => $block) {
            if ($block['type'] === 'text') {
                $events[] = ['type' => 'content_block_start', 'index' => $i, 'content_block' => ['type' => 'text', 'text' => '']];
                $events[] = ['type' => 'content_block_delta', 'index' => $i, 'delta' => ['type' => 'text_delta', 'text' => $block['text']]];
            } else {
                $events[] = ['type' => 'content_block_start', 'index' => $i, 'content_block' => ['type' => 'tool_use', 'id' => $block['id'], 'name' => $block['name'], 'input' => []]];
                $events[] = ['type' => 'content_block_delta', 'index' => $i, 'delta' => ['type' => 'input_json_delta', 'partial_json' => json_encode($block['input'])]];
            }
            $events[] = ['type' => 'content_block_stop', 'index' => $i];
        }
        $events[] = ['type' => 'message_delta', 'delta' => ['stop_reason' => $stop], 'usage' => ['output_tokens' => 20]];

        return implode('', array_map(fn ($e) => 'event: '.$e['type']."\ndata: ".json_encode($e)."\n\n", $events));
    }

    // 1 — the same user id in two tenants is two people

    public function test_user_3_of_one_tenant_never_sees_user_3_of_another(): void
    {
        $this->answers($this->text('Your order is ready.'));
        $a = $this->events($this->actingAs($this->in('shop-a'))->withHeaders($this->headers())->post('/rekhu/assistant/chat', ['message' => 'Where is my order 55?'])->streamedContent());
        $id = collect($a)->firstWhere('type', 'start')['conversation'];

        // tenant B's user 3 asks for the same conversation id: an empty chat, a list of their own
        $history = $this->actingAs($this->in('shop-b'))->getJson('/rekhu/assistant/chat?conversation='.$id, $this->headers(['Accept' => 'application/json']))->assertOk()->json();
        $this->assertSame([], $history['messages']);
        $this->assertSame([], $this->actingAs($this->in('shop-b'))->getJson('/rekhu/assistant/chat/conversations', $this->headers(['Accept' => 'application/json']))->json('conversations'));
        $this->assertSame('Where is my order 55?', $this->actingAs($this->in('shop-a'))->getJson('/rekhu/assistant/chat/conversations', $this->headers(['Accept' => 'application/json']))->json('conversations.0.title'));

        // nor can B continue it: a new conversation starts
        $this->answers($this->text('Hello.'));
        $b = $this->events($this->actingAs($this->in('shop-b'))->withHeaders($this->headers())->post('/rekhu/assistant/chat', ['message' => 'Hi', 'conversation' => $id])->streamedContent());
        Http::assertNotSent(fn (Sent $r) => str_contains(json_encode($r['messages'] ?? []), 'order 55') && ($r->header('x-tenant')[0] ?? null) === 'shop-b');
        $this->assertNotNull(collect($b)->firstWhere('type', 'start'));
    }

    public function test_the_owner_has_the_tenant_only_when_the_app_names_tenants(): void
    {
        $this->assertSame('tenant:shop-a|'.GenericUser::class.':3', Conversation::owner($this->in('shop-a'), null));
        RekhuAssistant::tenantUsing(null);
        $this->assertSame(GenericUser::class.':3', Conversation::owner($this->in('shop-a'), null), 'apps without tenants keep their keys');
    }

    // 2 — later work is put back in its tenant first

    public function test_a_queued_report_restores_its_tenant_before_it_runs(): void
    {
        $order = [];
        RekhuAssistant::restoreTenantUsing(function (array $tenant) use (&$order) {
            $order[] = 'restore '.$tenant['id'];
        });
        Http::fake(['rekhu.test/v1/reports' => function () use (&$order) {
            $order[] = 'send';

            return Http::response(['report' => ['id' => 1]], 201);
        }]);
        (new SendReport(['x-tenant' => 'shop-b', 'x-tenant-name' => 'Shop B', 'x-user' => '3', 'x-session' => 'assistant-1'], ['message' => 'm_2', 'reason' => 'other']))->handle();
        $this->assertSame(['restore shop-b', 'send'], $order);
    }

    // 3, 4, 5 — a tool of several steps, with progress and usage of its own

    public function test_a_tool_calls_routes_shows_progress_and_reports_its_usage(): void
    {
        Event::fake([AnswerCompleted::class]);
        $this->answers(
            $this->streamed([['type' => 'tool_use', 'id' => 'tu_1', 'name' => 'quote_marketplace', 'input' => ['product' => 'flyer-a5']]], 'tool_use'),
            $this->text('The cheapest is € 89.'),
        );
        $events = $this->events($this->actingAs($this->in('shop-a'))->withHeaders($this->headers())->post('/rekhu/assistant/chat', ['message' => 'Prices for flyers?'])->streamedContent());

        $this->assertNotNull(collect($events)->firstWhere('label', 'Comparing 2 suppliers…'));
        $this->assertSame([['label' => 'Supplier A', 'value' => '€ 89']], collect($events)->firstWhere('kind', 'offers')['data']['rows']);
        // the route ran as the chat's user, and the assistant reads only the final result
        Http::assertSent(fn (Sent $r) => str_contains(json_encode($r['messages'] ?? []), '\"best\":89') && str_contains(json_encode($r['messages'] ?? []), '\"user\":3') && ! str_contains(json_encode($r['messages'] ?? []), '_usage'));
        Event::assertDispatched(AnswerCompleted::class, fn (AnswerCompleted $e) => $e->actionUsage === [
            ['action' => 'quote_marketplace', 'kind' => 'marketplace_quote', 'units' => 2],
            ['action' => 'quote_marketplace', 'kind' => 'preflight', 'units' => 1, 'meta' => ['source' => 'route']],
        ] && $e->tenant['id'] === 'shop-a');
    }

    // 6 — on or off per tenant

    public function test_the_chat_is_not_available_where_the_app_says_so(): void
    {
        RekhuAssistant::enabledUsing(fn ($user, $tenant, $scope) => $tenant['id'] !== 'shop-b');
        $this->actingAs($this->in('shop-b'))->getJson('/rekhu/assistant/chat', $this->headers(['Accept' => 'application/json']))
            ->assertStatus(403)->assertJson(['available' => false, 'message' => 'The assistant is not available here.']);
        $this->actingAs($this->in('shop-b'))->withHeaders($this->headers())->post('/rekhu/assistant/chat', ['message' => 'Hi'])->assertStatus(403)->assertJson(['available' => false]);
        $this->actingAs($this->in('shop-a'))->getJson('/rekhu/assistant/chat', $this->headers(['Accept' => 'application/json']))->assertOk();
    }

    // 7 — white-label per tenant

    public function test_a_tenant_gets_its_own_brand_and_no_rekhu_names(): void
    {
        config(['rekhu-assistant.brand.rekhu' => false]);
        RekhuAssistant::brandUsing(fn ($tenant) => ['name' => 'Drukhulp '.$tenant['id'], 'logo' => 'https://shop-a.test/logo.png', 'colors' => ['accent' => '#e11d48', 'text' => 'red; background:url(x)']]);
        $brand = $this->actingAs($this->in('shop-a'))->getJson('/rekhu/assistant/chat', $this->headers(['Accept' => 'application/json']))->json('brand');
        $this->assertSame(['name' => 'Drukhulp shop-a', 'logo' => 'https://shop-a.test/logo.png', 'colors' => ['accent' => '#e11d48'], 'rekhu' => false], $brand);

        $this->answers($this->text('Hello.'));
        $this->actingAs($this->in('shop-a'))->withHeaders($this->headers())->post('/rekhu/assistant/chat', ['message' => 'Hi'])->streamedContent();
        Http::assertSent(fn (Sent $r) => str_contains(json_encode($r['system'] ?? []), 'You are called \"Drukhulp shop-a\"') && str_contains(json_encode($r['system'] ?? []), 'Never name Rekhu'));
    }

    public function test_without_a_brand_nothing_changes(): void
    {
        $this->assertArrayNotHasKey('brand', $this->actingAs($this->in('shop-a'))->getJson('/rekhu/assistant/chat', $this->headers(['Accept' => 'application/json']))->json());
    }
}

class QuoteMarketplace extends Tool
{
    protected string $description = 'Quotes a product with every supplier and gives the cheapest';

    protected array $rules = ['product' => 'required|string'];

    public function handle(Request $request): array
    {
        $this->step('Comparing 2 suppliers…');
        $first = $this->route('cart.price', ['product' => $request->input('product'), 'quantity' => 1000]);
        $this->card('offers', ['rows' => [['label' => 'Supplier A', 'value' => '€ '.$first['result']['price']]]], 'First offers');
        $this->usage('marketplace_quote', 2);
        $check = $this->route('preflight.check');
        foreach ($check['result']['_usage'] ?? [] as $u) {
            $this->usage($u['kind'], $u['units'], ['source' => 'route']);
        }

        return ['best' => $first['result']['price'], 'user' => $first['result']['tenant_user'], 'conversation' => $this->conversation()['id'] !== null];
    }
}
