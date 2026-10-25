<?php

declare(strict_types=1);

namespace Rekhu\Assistant\Tests\Feature;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Rekhu\Assistant\Attributes\Tool;
use Rekhu\Assistant\Runtime\ChatLog;
use Rekhu\Assistant\Tests\TestCase;

final class DeveloperToolsTest extends TestCase
{
    private string $log;

    protected function setUp(): void
    {
        parent::setUp();
        $this->log = sys_get_temp_dir().'/rekhu-assistant-test-'.uniqid().'.log';
        config(['rekhu-assistant.log.path' => $this->log, 'rekhu-assistant.log.days' => 0]);
        ChatLog::reset();
    }

    protected function tearDown(): void
    {
        foreach (glob(dirname($this->log).'/'.pathinfo($this->log, PATHINFO_FILENAME).'*') as $file) {
            @unlink($file);
        }
        ChatLog::reset();
        parent::tearDown();
    }

    protected function defineRoutes($router): void
    {
        $router->get('/api/shops', [ShopsController::class, 'index'])->name('api.shops');
        $router->get('/api/shops/{shop}', [ShopsController::class, 'show'])->name('api.shops.show');
    }

    public function test_the_chat_log_has_the_question_the_actions_and_the_answer(): void
    {
        $this->answersWithOneAction('list_shops', ['search' => 'Olaya'], 'Olaya is open.');
        config(['rekhu-assistant.debug' => true]);

        $events = $this->events($this->actingAs($this->user())->withHeaders($this->headers())->post('/rekhu/assistant/chat', ['message' => 'Is Olaya open?'])->streamedContent());

        $log = $this->logged();
        $this->assertStringContainsString('question {"conversation":', $log);
        $this->assertStringContainsString('"text":"Is Olaya open?"', $log);
        $this->assertStringContainsString('action {"conversation":', $log);
        $this->assertStringContainsString('"action":"list_shops","input":{"search":"Olaya"},"ok":true', $log);
        $this->assertStringNotContainsString('Riyadh', $log);
        $this->assertStringContainsString('answer {"conversation":', $log);

        $debug = array_values(array_filter($events, fn ($e) => $e['type'] === 'debug'));
        $this->assertCount(1, $debug);
        $this->assertSame('list_shops', $debug[0]['action']);
        $this->assertSame(['search' => 'Olaya'], $debug[0]['input']);
        $this->assertTrue($debug[0]['ok']);
        $this->assertStringContainsString('Riyadh', $debug[0]['result']);
    }

    public function test_a_failed_action_is_logged_with_why_and_debug_is_off_outside_local(): void
    {
        $this->answersWithOneAction('show_shop', ['shop' => 99], 'There is no such shop.');

        $events = $this->events($this->actingAs($this->user())->withHeaders($this->headers())->post('/rekhu/assistant/chat', ['message' => 'Open shop 99'])->streamedContent());

        $this->assertStringContainsString('local.WARNING: action failed', str_replace('testing.', 'local.', $this->logged()));
        $this->assertStringContainsString('"error":"that record does not exist, or the user cannot see it"', $this->logged());
        $this->assertSame([], array_values(array_filter($events, fn ($e) => $e['type'] === 'debug')));
    }

    public function test_the_log_can_be_turned_off(): void
    {
        config(['rekhu-assistant.log.enabled' => false]);
        $this->answersWithOneAction('list_shops', [], 'Two shops.');

        $this->actingAs($this->user())->withHeaders($this->headers())->post('/rekhu/assistant/chat', ['message' => 'Shops?'])->streamedContent();

        $this->assertSame('', $this->logged());
    }

    public function test_try_runs_one_action_as_the_user_and_shows_what_the_assistant_reads(): void
    {
        Schema::create('users', fn (Blueprint $table) => $table->id() && $table->string('name') && $table->string('email')->nullable() && $table->string('password')->nullable() && $table->timestamps());
        DB::table('users')->insert(['id' => 7, 'name' => 'Ada']);

        $this->artisan('rekhu:assistant:try', ['action' => 'list_shops', '--input' => '{"search":"Olaya"}'])
            ->expectsOutputToContain('list_shops is not offered to visitors')
            ->assertFailed();

        $this->artisan('rekhu:assistant:try', ['action' => 'list_shops', '--user' => 7, '--locale' => 'ar', '--input' => '{"search":"Olaya"}'])
            ->expectsOutputToContain('list_shops as user 7, in ar')
            ->expectsOutputToContain('OK in')
            ->expectsOutputToContain('"city": "Riyadh"')
            ->assertSuccessful();

        $this->artisan('rekhu:assistant:try', ['action' => 'show_shop', '--user' => 7, '--input' => '{"shop":99}'])
            ->expectsOutputToContain('ms: that record does not exist, or the user cannot see it')
            ->assertFailed();

        $this->artisan('rekhu:assistant:try', ['action' => 'nope', '--user' => 7])
            ->expectsOutputToContain('There is no action nope')
            ->assertFailed();

        $this->artisan('rekhu:assistant:try', ['--user' => 7])
            ->expectsOutputToContain('list_shops, show_shop')
            ->assertSuccessful();
    }

    public function test_check_finds_unclear_actions_and_records_looked_up_by_another_key(): void
    {
        $this->artisan('rekhu:assistant:check')
            ->expectsOutputToContain('Inputs without a meaning: search')
            ->expectsOutputToContain('{shop} is looked up by its code, not by id.')
            ->expectsOutputToContain('The description is too short')
            ->assertFailed();
    }

    private function answersWithOneAction(string $tool, array $input, string $answer): void
    {
        $call = $this->stream([['type' => 'tool_use', 'id' => 'tu_1', 'name' => $tool, 'input' => $input]], 'tool_use');
        $done = $this->stream([['type' => 'text', 'text' => $answer]], 'end_turn');
        Http::fake(['rekhu.test/*' => Http::sequence()->push($call, 200, ['Content-Type' => 'text/event-stream'])->push($done, 200, ['Content-Type' => 'text/event-stream'])]);
    }

    private function stream(array $blocks, string $stop): string
    {
        $events = [['type' => 'message_start', 'message' => ['content' => []]]];
        foreach ($blocks as $i => $block) {
            if ($block['type'] === 'text') {
                $events[] = ['type' => 'content_block_start', 'index' => $i, 'content_block' => ['type' => 'text', 'text' => '']];
                $events[] = ['type' => 'content_block_delta', 'index' => $i, 'delta' => ['type' => 'text_delta', 'text' => $block['text']]];
            } else {
                $events[] = ['type' => 'content_block_start', 'index' => $i, 'content_block' => ['type' => 'tool_use', 'id' => $block['id'], 'name' => $block['name'], 'input' => []]];
                $events[] = ['type' => 'content_block_delta', 'index' => $i, 'delta' => ['type' => 'input_json_delta', 'partial_json' => json_encode((object) $block['input'])]];
            }
            $events[] = ['type' => 'content_block_stop', 'index' => $i];
        }
        $events[] = ['type' => 'message_delta', 'delta' => ['stop_reason' => $stop]];
        $events[] = ['type' => 'message_stop'];

        return implode('', array_map(fn ($e) => 'event: '.$e['type']."\ndata: ".json_encode($e)."\n\n", $events));
    }

    private function logged(): string
    {
        $files = glob(dirname($this->log).'/'.pathinfo($this->log, PATHINFO_FILENAME).'*');

        return implode('', array_map('file_get_contents', $files ?: []));
    }
}

class Shop extends Model
{
    public function getRouteKeyName(): string
    {
        return 'code';
    }
}

class ShopsController extends Controller
{
    #[Tool('The shops of the company with their city and opening hours', name: 'list_shops', rules: ['search' => 'nullable|string'])]
    public function index(Request $request)
    {
        return response()->json(['data' => [['id' => 1, 'name' => 'Olaya', 'city' => 'Riyadh']]]);
    }

    #[Tool('A shop', name: 'show_shop')]
    public function show(Shop $shop)
    {
        abort(404);
    }
}
