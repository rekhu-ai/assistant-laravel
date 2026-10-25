<?php

declare(strict_types=1);

namespace Rekhu\Assistant\Tests\Feature;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Cache;
use Rekhu\Assistant\Attributes\Tool;
use Rekhu\Assistant\Catalogue\Actions;
use Rekhu\Assistant\RekhuAssistant;
use Rekhu\Assistant\Runtime\Dispatcher;
use Rekhu\Assistant\Tests\TestCase;

final class FilesTest extends TestCase
{
    protected function tearDown(): void
    {
        RekhuAssistant::accessUsing(null);
        parent::tearDown();
    }

    protected function defineRoutes($router): void
    {
        $router->get('/api/transactions/export', [ExportController::class, 'csv'])->name('api.export');
        $router->get('/api/transactions/report', [ExportController::class, 'report'])->name('api.report');
    }

    public function test_a_download_becomes_a_file_behind_a_signed_link(): void
    {
        $result = $this->action('export_transactions');

        $this->assertTrue($result['ok']);
        $this->assertSame('file', $result['result']['status']);
        $this->assertSame('transactions.csv', $result['result']['name']);
        $this->assertSame(15, $result['result']['expires_in_minutes']);

        $download = $this->get($result['result']['url']);
        $download->assertOk();
        $this->assertSame("invoice,total\nINV-1,115\n", $download->getContent());
        $this->assertStringContainsString('attachment; filename="transactions.csv"', $download->headers->get('Content-Disposition'));

        // without its signature the link is refused, and once expired it is gone
        $this->get(strtok($result['result']['url'], '?'))->assertForbidden();
        Cache::flush();
        $this->get($result['result']['url'])->assertStatus(410);
    }

    public function test_a_file_on_disk_is_handed_over_and_a_temporary_one_removed(): void
    {
        $result = $this->action('transactions_report');

        $this->assertSame('report.txt', $result['result']['name']);
        $this->assertFileDoesNotExist(sys_get_temp_dir().'/rekhu-report-test.txt');
        $this->assertSame('the report', $this->get($result['result']['url'])->getContent());
    }

    public function test_a_file_over_the_limit_is_refused(): void
    {
        config(['rekhu-assistant.files.max_mb' => 0]);

        $this->assertSame(false, $this->action('export_transactions')['ok']);
    }

    public function test_the_apps_access_rule_decides_who_may_use_the_chat(): void
    {
        RekhuAssistant::accessUsing(fn ($user) => $user->getAuthIdentifier() === 7);
        $this->rekhuAnswers('Hello');

        $this->actingAs($this->user(8))->withHeaders($this->headers())->postJson('/rekhu/assistant/chat', ['message' => 'Hi'])
            ->assertForbidden()->assertJson(['message' => 'You cannot use the assistant. Ask an administrator for access.']);
        $this->actingAs($this->user(7))->withHeaders($this->headers())->post('/rekhu/assistant/chat', ['message' => 'Hi'])->assertOk();
    }

    private function action(string $name): array
    {
        $outer = Request::create('/rekhu/assistant/chat', 'POST');
        $outer->setUserResolver(fn () => $this->user());

        return Dispatcher::run(Actions::for($this->user())[$name], [], $outer);
    }
}

class ExportController extends Controller
{
    #[Tool('Export the transactions as CSV', name: 'export_transactions')]
    public function csv()
    {
        return response()->streamDownload(function () {
            echo "invoice,total\nINV-1,115\n";
        }, 'transactions.csv', ['Content-Type' => 'text/csv']);
    }

    #[Tool('The transactions report', name: 'transactions_report')]
    public function report()
    {
        $path = sys_get_temp_dir().'/rekhu-report-test.txt';
        file_put_contents($path, 'the report');

        return response()->download($path, 'report.txt')->deleteFileAfterSend();
    }
}
