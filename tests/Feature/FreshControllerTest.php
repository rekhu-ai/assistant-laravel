<?php

declare(strict_types=1);

namespace Rekhu\Assistant\Tests\Feature;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Rekhu\Assistant\Attributes\Tool;
use Rekhu\Assistant\Catalogue\Actions;
use Rekhu\Assistant\Runtime\Dispatcher;
use Rekhu\Assistant\Tests\TestCase;

final class FreshControllerTest extends TestCase
{
    protected function defineRoutes($router): void
    {
        $router->get('/api/revenue', [RevenueController::class, 'index'])->name('api.revenue');
    }

    public function test_each_call_gets_a_controller_built_from_its_own_input(): void
    {
        $outer = Request::create('/api/rekhu/assistant/chat', 'POST');
        $outer->setUserResolver(fn () => $this->user());
        $this->app->instance('request', $outer);

        // listing the actions reads each route's middleware, which builds its controller from the chat request
        $action = Actions::for($this->user())['revenue'];

        $first = Dispatcher::run($action, ['period' => 'this_month'], $outer);
        $second = Dispatcher::run($action, ['period' => 'last_month'], $outer);

        $this->assertSame('this_month', $first['result']['period']);
        $this->assertSame('last_month', $second['result']['period']);
    }
}

/** Reads the request in its constructor, as many apps do through an injected service. */
class RevenueController extends Controller
{
    private string $period;

    public function __construct(Request $request)
    {
        $this->period = $request->input('period', 'this_year');
    }

    #[Tool('Revenue for a period', name: 'revenue', rules: ['period' => 'nullable|string'])]
    public function index()
    {
        return response()->json(['period' => $this->period]);
    }
}
