<?php

declare(strict_types=1);

namespace Rekhu\Assistant\Tests\Feature;

use Illuminate\Support\Facades\Http;
use Rekhu\Assistant\Attributes\Tool;
use Rekhu\Assistant\Catalogue\Actions;
use Rekhu\Assistant\Catalogue\ActionsFile;
use Rekhu\Assistant\Tests\TestCase;

final class ActionsFileTest extends TestCase
{
    private string $file;

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $this->file = sys_get_temp_dir().'/rekhu-actions-'.getmypid().'-'.uniqid().'.php';
        $app['config']->set('rekhu-assistant.actions.file', $this->file);
    }

    protected function tearDown(): void
    {
        @unlink($this->file);
        @unlink(dirname($this->file).'/questions.php');
        ActionsFile::forget();
        parent::tearDown();
    }

    protected function defineRoutes($router): void
    {
        $router->get('orders', [ScannedController::class, 'index'])->name('orders.index');
        $router->delete('orders/{order}', [ScannedController::class, 'destroy'])->name('orders.destroy');
        $router->get('invoices', [ScannedController::class, 'invoices'])->name('invoices.index');
        $router->post('newsletter', fn () => 'ok');
    }

    private function writeFile(array $entries): void
    {
        ActionsFile::write($entries);
    }

    public function test_only_entries_that_are_on_are_offered(): void
    {
        $this->writeFile([
            'orders.index' => ['on' => true, 'description' => 'Lists the orders of the user with number, status and total'],
            'orders.destroy' => ['on' => false, 'description' => 'Removes an order'],
        ]);
        $actions = Actions::for($this->user());
        // offered under the name the model sees
        $this->assertArrayHasKey('orders_index', $actions);
        $this->assertSame('file', $actions['orders_index']['source']);
        $this->assertArrayNotHasKey('orders_destroy', $actions);
    }

    public function test_the_code_wins_over_the_file(): void
    {
        $this->writeFile(['invoices.index' => ['on' => true, 'description' => 'From the file, never used']]);
        $actions = Actions::for($this->user());
        $this->assertSame('attribute', $actions['invoices_index']['source']);
        $this->assertSame('Lists the invoices of the user, from the code', $actions['invoices_index']['description']);
    }

    public function test_a_deleting_action_from_the_file_is_confirmed_by_default(): void
    {
        $this->writeFile(['orders.destroy' => ['on' => true, 'description' => 'Removes an order of the user that has not shipped']]);
        $this->assertTrue(Actions::for($this->user())['orders_destroy']['confirm']);
    }

    public function test_a_merge_keeps_what_the_developer_wrote_adds_new_ones_off_and_marks_gone_ones(): void
    {
        $current = [
            'orders.index' => ['on' => true, 'description' => 'My own words'],
            'old.route' => ['on' => true, 'description' => 'Was there once'],
        ];
        $proposal = [
            'orders.index' => ['on' => true, 'description' => 'Rekhu would say this'],
            'orders.destroy' => ['on' => false, 'description' => 'Removes an order', 'why_off' => 'removes data'],
            'POST newsletter' => ['on' => true, 'description' => 'Signs the user up for the newsletter', 'confirm' => true],
        ];
        $merged = ActionsFile::merge($current, $proposal, ['orders.index', 'orders.destroy', 'POST newsletter']);

        $this->assertSame('My own words', $merged['entries']['orders.index']['description']);
        $this->assertFalse($merged['entries']['POST newsletter']['on'], 'new is always off');
        $this->assertSame('new', $merged['entries']['POST newsletter']['state']);
        $this->assertSame('on', $merged['entries']['POST newsletter']['proposed']);
        $this->assertSame('gone', $merged['entries']['old.route']['state']);
        $this->assertTrue($merged['entries']['old.route']['on'], 'a gone entry is marked, not removed');
        $this->assertSame(['orders.destroy', 'POST newsletter'], $merged['added']);
    }

    public function test_the_file_written_reads_back_the_same(): void
    {
        $entries = ['orders.index' => ['on' => true, 'description' => "It's the user's orders", 'fields' => ['id', 'status'], 'inputs' => ['status' => 'open or closed'], 'label' => ['en' => 'Orders', 'ar' => 'الطلبات']]];
        $this->writeFile($entries);
        $this->assertSame($entries, ActionsFile::all());
    }

    public function test_check_finds_doubles_routes_that_are_gone_and_deleting_without_a_click(): void
    {
        $this->writeFile([
            'invoices.index' => ['on' => true, 'description' => 'Also in the code'],
            'nowhere' => ['on' => true, 'description' => 'No such route'],
            'orders.destroy' => ['on' => true, 'confirm' => false, 'description' => 'Removes an order of the user right away'],
        ]);
        $this->artisan('rekhu:assistant:check', ['--no-run' => true])
            ->expectsOutputToContain('also defined in the code')
            ->expectsOutputToContain('No route has this name or address')
            ->expectsOutputToContain('deletes without a click')
            ->assertFailed();
    }

    public function test_check_says_when_the_file_is_missing(): void
    {
        $this->artisan('rekhu:assistant:check', ['--no-run' => true])->expectsOutputToContain('rekhu:assistant:scan proposes the rest');
    }

    public function test_the_scan_sends_routes_never_the_code_wins_and_writes_the_file(): void
    {
        Http::fake([
            'rekhu.test/v1/assistant/scan' => Http::response(['actions' => [
                'orders.index' => ['on' => true, 'description' => 'Lists the orders of the user with number, status and total', 'confirm' => false],
                'orders.destroy' => ['on' => false, 'description' => 'Removes an order', 'why_off' => 'removes data'],
            ], 'questions' => [['question' => 'What are my open orders?', 'action' => 'orders.index']]]),
            'rekhu.test/v1/assistant/scan/finish' => Http::response(['scan' => ['id' => 1], 'knowledge_draft' => true], 201),
        ]);
        $this->artisan('rekhu:assistant:scan')->expectsOutputToContain('2 new')->assertSuccessful();

        Http::assertSent(function ($request) {
            if (! str_ends_with($request->url(), '/v1/assistant/scan')) {
                return false;
            }
            $keys = array_column($request['routes'], 'key');

            // invoices.index has #[Tool]: the code decides it, so it is not sent
            return in_array('orders.index', $keys, true) && ! in_array('invoices.index', $keys, true) && $request->header('x-api-key')[0] === self::KEY;
        });
        $entries = ActionsFile::all();
        $this->assertFalse($entries['orders.index']['on']);
        $this->assertSame('on', $entries['orders.index']['proposed']);
        $this->assertFileExists(dirname($this->file).'/questions.php');

        // a second scan only sends what the file does not have yet
        Http::fake(['rekhu.test/v1/assistant/scan/finish' => Http::response(['scan' => ['id' => 2]], 201), 'rekhu.test/*' => Http::response(['actions' => [], 'questions' => []])]);
        $this->artisan('rekhu:assistant:scan')->assertSuccessful();
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/v1/assistant/scan') && ! in_array('orders.index', array_column($request['routes'], 'key'), true));
    }

    public function test_restore_writes_the_last_kept_file_back(): void
    {
        Http::fake(['rekhu.test/v1/assistant/scan/latest' => Http::response(['actions' => ['orders.index' => ['on' => true, 'description' => 'Kept in Rekhu']], 'offered' => 1, 'total' => 1, 'at' => '2026-11-05'])]);
        $this->artisan('rekhu:assistant:scan', ['--restore' => true])->assertSuccessful();
        $this->assertSame('Kept in Rekhu', ActionsFile::all()['orders.index']['description']);
        $this->artisan('rekhu:assistant:scan', ['--restore' => true])->expectsOutputToContain('--force')->assertFailed();
    }
}

final class ScannedController
{
    public function index()
    {
        return ['orders' => []];
    }

    public function destroy(string $order)
    {
        return ['removed' => $order];
    }

    #[Tool('Lists the invoices of the user, from the code')]
    public function invoices()
    {
        return ['invoices' => []];
    }
}
