<?php

declare(strict_types=1);

namespace Rekhu\Assistant\Tests\Feature;

use Illuminate\Http\Client\Request as Sent;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Rekhu\Assistant\Card;
use Rekhu\Assistant\Contracts\ConversationStore;
use Rekhu\Assistant\Events\AnswerCompleted;
use Rekhu\Assistant\RekhuAssistant;
use Rekhu\Assistant\Runtime\Inspector;
use Rekhu\Assistant\Tests\TestCase;
use Rekhu\Assistant\Tool;

/**
 * What an app needs to resell the assistant to its own customers: usage per answer to charge from, the model per
 * plan, chats of their own (scopes), its own cards, its own conversation store, and files its users attach.
 */
final class ResellTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('rekhu-assistant.scopes', ['marketplace' => ['instructions' => 'You compare suppliers.', 'actions' => ['compare_*']]]);
        $app['config']->set('rekhu-assistant.debug', false);
        RekhuAssistant::tools([PriceTool::class, CompareSuppliers::class, AttachArtwork::class]);
    }

    protected function tearDown(): void
    {
        RekhuAssistant::modelUsing(null);
        RekhuAssistant::conversationsUsing(null);
        parent::tearDown();
    }

    protected function defineRoutes($router): void
    {
        $router->post('orders/{order}/artwork', fn (Request $request, string $order) => [
            'order' => $order, 'name' => $request->file('artwork')?->getClientOriginalName(), 'bytes' => $request->file('artwork')?->getSize(),
        ])->name('orders.artwork');
    }

    /** A streamed answer with these blocks, and the usage Rekhu reports for it. */
    private function streamed(array $blocks, string $stop, array $usage = ['input_tokens' => 1200, 'output_tokens' => 80]): string
    {
        $events = [['type' => 'message_start', 'message' => ['content' => [], 'model' => 'Rekhu Standard', 'usage' => ['input_tokens' => $usage['input_tokens'], 'cache_read_input_tokens' => 300]]]];
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
        $events[] = ['type' => 'message_delta', 'delta' => ['stop_reason' => $stop], 'usage' => ['output_tokens' => $usage['output_tokens']]];

        return implode('', array_map(fn ($e) => 'event: '.$e['type']."\ndata: ".json_encode($e)."\n\n", $events));
    }

    private array $queue = [];

    private bool $faked = false;

    /** Rekhu answers the next model calls with these streams, in order; more can be added later in the test. */
    private function sequence(string ...$bodies): void
    {
        array_push($this->queue, ...$bodies);
        if (! $this->faked) {
            $this->faked = true;
            Http::fake(['rekhu.test/*' => fn () => Http::response(array_shift($this->queue) ?? '', 200, ['Content-Type' => 'text/event-stream'])]);
        }
    }

    public function test_an_answer_reports_its_model_tokens_calls_and_actions(): void
    {
        Event::fake([AnswerCompleted::class]);
        $this->sequence(
            $this->streamed([['type' => 'tool_use', 'id' => 'tu_1', 'name' => 'price', 'input' => ['quantity' => 1000]]], 'tool_use'),
            $this->streamed([['type' => 'text', 'text' => 'It is € 89.']], 'end_turn', ['input_tokens' => 1500, 'output_tokens' => 40]),
        );
        $events = $this->events($this->actingAs($this->user())->withHeaders($this->headers())->post('/rekhu/assistant/chat', ['message' => 'What do 1000 flyers cost?'])->streamedContent());

        Event::assertDispatched(AnswerCompleted::class, function (AnswerCompleted $e) {
            return $e->model === 'Rekhu Standard' && $e->inputTokens === 2700 && $e->outputTokens === 120 && $e->cacheReadTokens === 600
                && $e->calls === 2 && $e->actions === ['price'] && ! $e->failed && $e->answer !== null && $e->user?->getAuthIdentifier() === 7;
        });
        // the app's own card reached the chat, and the model only heard it was shown
        $card = collect($events)->firstWhere('kind', 'price');
        $this->assertSame('card', $card['type']);
        $this->assertSame([['label' => 'Standard', 'value' => '€ 89']], $card['data']['rows']);
        Http::assertSent(fn (Sent $r) => str_contains(json_encode($r['messages'] ?? []), 'cards_shown') && ! str_contains(json_encode($r['messages'] ?? []), '_cards'));
    }

    public function test_the_model_follows_the_plan_and_a_scope_has_its_own_actions_and_task(): void
    {
        RekhuAssistant::modelUsing(fn ($user, $request, $scope) => $scope === 'marketplace' ? 'Rekhu Fast' : 'Rekhu Pro');
        $this->rekhuAnswers('Hello.');
        $this->actingAs($this->user())->withHeaders($this->headers())->post('/rekhu/assistant/chat', ['message' => 'Hi'])->streamedContent();
        Http::assertSent(fn (Sent $r) => ($r['model'] ?? null) === 'Rekhu Pro' && in_array('price', array_column($r['tools'] ?? [], 'name'), true));

        $this->rekhuAnswers('Hello there.');
        $this->actingAs($this->user())->withHeaders($this->headers())->post('/rekhu/assistant/chat', ['message' => 'Compare', 'scope' => 'marketplace'])->streamedContent();
        Http::assertSent(function (Sent $r) {
            $tools = array_column($r['tools'] ?? [], 'name');

            return ($r['model'] ?? null) === 'Rekhu Fast' && $tools === ['compare_suppliers'] && str_contains(json_encode($r['system']), 'You compare suppliers.');
        });
    }

    public function test_conversations_go_to_the_apps_own_store_and_are_listed(): void
    {
        $store = new MemoryConversations;
        RekhuAssistant::conversationsUsing($store);
        $this->rekhuAnswers('Hello.');
        $this->actingAs($this->user())->withHeaders($this->headers())->post('/rekhu/assistant/chat', ['message' => 'My first question'])->streamedContent();

        $this->assertCount(1, $store->rows);
        $row = array_values($store->rows)[0];
        $this->assertSame('My first question', $row['about']['title']);
        $this->assertSame(7, $row['about']['user']->getAuthIdentifier());
        $list = $this->actingAs($this->user())->getJson('/rekhu/assistant/chat/conversations', $this->headers(['Accept' => 'application/json']))->assertOk()->json('conversations');
        $this->assertSame('My first question', $list[0]['title']);
    }

    public function test_attached_files_are_described_shown_to_the_model_and_handed_to_an_action(): void
    {
        Storage::fake('local');
        $this->sequence(
            $this->streamed([['type' => 'text', 'text' => 'I see a PDF and a picture.']], 'end_turn'),
        );
        $png = UploadedFile::fake()->image('logo.png', 600, 400);
        $pdf = UploadedFile::fake()->createWithContent('flyer.pdf', "%PDF-1.7\n1 0 obj << /Type /Pages /Count 2 /MediaBox [0 0 595.28 841.89] /TrimBox [8.5 8.5 586.77 833.39] >> endobj\n%%EOF");
        $events = $this->events($this->actingAs($this->user())->withHeaders($this->headers())
            ->post('/rekhu/assistant/chat', ['message' => 'Check my files', 'files' => [$png, $pdf]])->streamedContent());

        $start = collect($events)->firstWhere('type', 'start');
        $this->assertCount(2, $start['files']);
        $this->assertStringContainsString('600 × 400 px', $start['files'][0]['about']);
        $this->assertStringContainsString('2 pages', $start['files'][1]['about']);
        $this->assertStringContainsString('page 210 × 297 mm', $start['files'][1]['about']);
        Http::assertSent(function (Sent $r) {
            $messages = $r->data()['messages'] ?? [];
            $last = json_encode(end($messages));

            return str_contains($last, 'Files the user attached') && str_contains($last, '"type":"image"') && str_contains($last, '"type":"document"');
        });

        // the next answer hands the PDF to a route that takes an upload
        $id = $start['files'][1]['id'];
        $conversation = $start['conversation'];
        $this->sequence(
            $this->streamed([['type' => 'tool_use', 'id' => 'tu_2', 'name' => 'attach_artwork', 'input' => ['order' => '55', 'artwork' => $id]]], 'tool_use'),
            $this->streamed([['type' => 'text', 'text' => 'Attached.']], 'end_turn'),
        );
        $this->actingAs($this->user())->withHeaders($this->headers())->post('/rekhu/assistant/chat', ['message' => 'Attach the PDF to order 55', 'conversation' => $conversation])->streamedContent();
        Http::assertSent(fn (Sent $r) => str_contains(json_encode($r['messages'] ?? []), '\"name\":\"flyer.pdf\"'));
    }

    public function test_a_zip_is_unpacked_safely(): void
    {
        if (! class_exists(\ZipArchive::class)) {
            $this->markTestSkipped('no zip extension');
        }
        Storage::fake('local');
        $path = tempnam(sys_get_temp_dir(), 'zip');
        $zip = new \ZipArchive;
        $zip->open($path, \ZipArchive::OVERWRITE);
        $zip->addFromString('artwork/front.pdf', "%PDF-1.4\n/Type /Pages /Count 1");
        $zip->addFromString('artwork/back.pdf', "%PDF-1.4\n/Type /Pages /Count 1");
        $zip->addFromString('__MACOSX/._front.pdf', 'resource');
        $zip->addFromString('../escape.pdf', 'never outside');
        $zip->addFromString('virus.exe', 'not allowed');
        $zip->close();
        $this->rekhuAnswers('Two PDFs.');
        $events = $this->events($this->actingAs($this->user())->withHeaders($this->headers())
            ->post('/rekhu/assistant/chat', ['message' => 'My artwork', 'files' => [new UploadedFile($path, 'artwork.zip', 'application/zip', null, true)]])->streamedContent());

        $names = array_column(collect($events)->firstWhere('type', 'start')['files'], 'name');
        $this->assertSame(['artwork.zip', 'artwork_front.pdf', 'artwork_back.pdf'], $names);
        Http::assertSent(fn (Sent $r) => str_contains(json_encode($r['messages'] ?? []), 'virus.exe (not an allowed type)'));
        @unlink($path);
    }

    public function test_the_inspector_reads_print_details(): void
    {
        $jpg = tempnam(sys_get_temp_dir(), 'jpg');
        imagejpeg(imagecreatetruecolor(1181, 1772), $jpg);
        // a JFIF header that says 300 dpi
        $bytes = file_get_contents($jpg);
        $at = strpos($bytes, "JFIF\0");
        $bytes = substr_replace($bytes, "\x01".pack('n', 300).pack('n', 300), $at + 7, 5);
        file_put_contents($jpg, $bytes);
        $info = Inspector::of($jpg, 'jpg');
        $this->assertSame(1181, $info['width_px']);
        $this->assertSame(300, $info['dpi']);
        $this->assertSame([100.0, 150.0], $info['print_mm']);
        $this->assertSame('RGB', $info['colour']);
        @unlink($jpg);
    }

    public function test_a_card_kind_of_the_package_is_refused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Card::make('confirm');
    }
}

class PriceTool extends Tool
{
    protected string $description = 'The price of a quantity of flyers, per delivery speed';

    protected array $rules = ['quantity' => 'required|integer'];

    public function name(): string
    {
        return 'price';
    }

    public function handle(Request $request): array
    {
        return Card::attach(['quantity' => $request->integer('quantity'), 'total' => 89], Card::make('price', ['rows' => [['label' => 'Standard', 'value' => '€ 89']]], '1000 flyers'));
    }
}

class CompareSuppliers extends Tool
{
    protected string $description = 'Compares the suppliers of a product on price and delivery';

    public function name(): string
    {
        return 'compare_suppliers';
    }

    public function handle(): array
    {
        return ['suppliers' => []];
    }
}

class AttachArtwork extends Tool
{
    protected string $description = 'Attaches a print file the user attached to one of their orders';

    protected array $rules = ['order' => 'required|string', 'artwork' => 'required|file|mimes:pdf'];

    public function name(): string
    {
        return 'attach_artwork';
    }

    public function handle(Request $request): array
    {
        $file = $request->input('artwork') ?? $request->file('artwork');

        return ['order' => $request->input('order'), 'name' => $file?->getClientOriginalName()];
    }
}

final class MemoryConversations implements ConversationStore
{
    public array $rows = [];

    public function get(string $owner, string $id): ?array
    {
        return $this->rows[$owner.'|'.$id]['data'] ?? null;
    }

    public function put(string $owner, string $id, array $data, int $minutes, array $about): void
    {
        $this->rows[$owner.'|'.$id] = ['data' => $data, 'about' => $about, 'at' => now()->toIso8601String()];
    }

    public function forget(string $owner, string $id): void
    {
        unset($this->rows[$owner.'|'.$id]);
    }

    public function list(string $owner, ?string $scope = null, int $limit = 30): array
    {
        $out = [];
        foreach ($this->rows as $key => $row) {
            if (str_starts_with($key, $owner.'|') && ($row['data']['scope'] ?? null) === $scope) {
                $out[] = ['id' => explode('|', $key, 2)[1], 'title' => $row['about']['title'], 'scope' => $scope, 'updated_at' => $row['at']];
            }
        }

        return $out;
    }
}
