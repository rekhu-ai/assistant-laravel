<?php

declare(strict_types=1);

namespace Rekhu\Assistant\Tests\Feature;

use Illuminate\Http\Client\Request as Sent;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Rekhu\Assistant\Tests\TestCase;
use Rekhu\Core\RekhuServiceProvider;

/** Rekhu's knowers in the chat: Seshat checks and fixes the file the user attached; the cards the user sees. */
final class KnowersTest extends TestCase
{
    private array $queue = [];

    private array $knowerCalls = [];

    protected function getPackageProviders($app): array
    {
        return [...parent::getPackageProviders($app), RekhuServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('rekhu.key', self::KEY);
        $app['config']->set('rekhu.platform', 'https://rekhu.test');
        $app['config']->set('rekhu.knowers', ['seshat', 'machines']);
        $app['config']->set('rekhu.ledger.enabled', false);
        $app['config']->set('rekhu-assistant.debug', false);
    }

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $finding = ['level' => 'warning', 'code' => 'bleed_missing', 'decision' => 'fix', 'fix' => 'add_bleed'];
        Http::fake(function (Sent $r) use ($finding) {
            $path = parse_url($r->url(), PHP_URL_PATH);
            if (str_starts_with($path, '/v1/knowers/')) {
                $this->knowerCalls[] = $path;
            }

            return match (true) {
                $path === '/v1/knowers/seshat/check' => Http::response(['ref' => 'k_'.str_repeat('a', 20), 'file_id' => 'f_orig', 'status' => 'warning', 'findings' => [$finding, ['level' => 'error', 'code' => 'resolution_too_low', 'decision' => 'ask']], 'meta' => ['model_calls' => 0, 'cost_usd' => 0]]),
                $path === '/v1/knowers/seshat/preview' => Http::response(['image' => 'data:image/png;base64,iVBORw0KGgo=', 'meta' => []]),
                $path === '/v1/knowers/seshat/fix' => Http::response(['file_id' => 'f_fixed', 'name' => 'flyer-print.pdf', 'applied' => [['fix' => 'add_bleed']], 'check' => ['status' => 'error', 'findings' => [['code' => 'resolution_too_low', 'decision' => 'ask']]], 'meta' => []]),
                $path === '/v1/knowers/seshat/files/f_fixed' => Http::response('%PDF-1.6 fixed', 200, ['Content-Type' => 'application/pdf']),
                default => Http::response(array_shift($this->queue) ?? '', 200, ['Content-Type' => 'text/event-stream']),
            };
        });
    }

    private function answers(string ...$streams): void
    {
        array_push($this->queue, ...$streams);
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

    public function test_an_attached_print_file_is_checked_and_fixed_by_seshat(): void
    {
        // the user attaches the flyer
        $this->answers($this->streamed([['type' => 'text', 'text' => 'What size is it for?']], 'end_turn'));
        $first = $this->events($this->actingAs($this->user())->withHeaders($this->headers())
            ->post('/rekhu/assistant/chat', ['message' => 'Please check my flyer', 'files' => [UploadedFile::fake()->createWithContent('flyer.pdf', "%PDF-1.7\nflyer")]])->streamedContent());
        $start = collect($first)->firstWhere('type', 'start');
        $att = $start['files'][0]['id'];
        Http::assertSent(fn (Sent $r) => in_array('seshat_check', array_column($r['tools'] ?? [], 'name'), true) && in_array('machines_choose', array_column($r['tools'] ?? [], 'name'), true));

        // A5: the model asks Seshat
        $this->answers(
            $this->streamed([['type' => 'tool_use', 'id' => 'tu_1', 'name' => 'seshat_check', 'input' => ['file' => $att, 'order' => ['width_mm' => 148, 'height_mm' => 210]]]], 'tool_use'),
            $this->streamed([['type' => 'text', 'text' => 'Seshat adds the bleed. The photo is too small: send a sharper one?']], 'end_turn'),
        );
        $events = $this->events($this->actingAs($this->user())->withHeaders($this->headers())
            ->post('/rekhu/assistant/chat', ['message' => 'A5', 'conversation' => $start['conversation']])->streamedContent());
        $card = collect($events)->firstWhere('kind', 'seshat');
        $this->assertSame('flyer.pdf', $card['title']);
        $this->assertSame('data:image/png;base64,iVBORw0KGgo=', $card['data']['image']);
        $this->assertSame([['label' => 'No bleed', 'value' => 'Seshat fixes it'], ['label' => 'Picture resolution too low', 'value' => 'Your choice']], $card['data']['rows']);
        $this->assertSame('Fix it', $card['data']['buttons'][0]['label']);
        Http::assertSent(fn (Sent $r) => parse_url($r->url(), PHP_URL_PATH) === '/v1/knowers/seshat/check' && $r->body() === "%PDF-1.7\nflyer"
            && $r->hasHeader('x-tenant') && $r->hasHeader('x-user', '7') && $r->hasHeader('x-file-name', 'flyer.pdf'));
        Http::assertSent(fn (Sent $r) => str_contains(json_encode($r['messages'] ?? []), 'f_orig') && str_contains(json_encode($r['messages'] ?? []), 'bleed_missing'));

        // the user agrees: the fix, a proof and the download
        $this->answers(
            $this->streamed([['type' => 'tool_use', 'id' => 'tu_2', 'name' => 'seshat_fix', 'input' => ['file_id' => 'f_orig', 'fixes' => 'auto', 'order' => ['width_mm' => 148, 'height_mm' => 210]]]], 'tool_use'),
            $this->streamed([['type' => 'text', 'text' => 'Done: bleed added.']], 'end_turn'),
        );
        $events = $this->events($this->actingAs($this->user())->withHeaders($this->headers())
            ->post('/rekhu/assistant/chat', ['message' => 'Fix flyer.pdf with Seshat', 'conversation' => $start['conversation']])->streamedContent());
        $file = collect($events)->firstWhere('kind', 'file');
        $this->assertSame('flyer-print.pdf', $file['name']);
        $this->get($file['url'])->assertOk()->assertSeeText('%PDF-1.6 fixed');
        $proof = collect($events)->where('kind', 'seshat')->first();
        $this->assertSame('The proof', $proof['title']);
        $this->assertSame([['label' => 'Bleed added', 'value' => 'done']], $proof['data']['rows']);
        Http::assertSent(fn (Sent $r) => str_contains(json_encode($r['messages'] ?? []), 'still_to_decide'));
    }

    public function test_no_knower_tools_when_none_is_installed_or_for_visitors(): void
    {
        config(['rekhu.knowers' => []]);
        $this->answers($this->streamed([['type' => 'text', 'text' => 'Hello.']], 'end_turn'));
        $this->actingAs($this->user())->withHeaders($this->headers())->post('/rekhu/assistant/chat', ['message' => 'Hi'])->streamedContent();
        Http::assertNotSent(fn (Sent $r) => str_contains(json_encode($r['tools'] ?? []), 'seshat_'));

        config(['rekhu.knowers' => ['seshat']]);
        $this->answers($this->streamed([['type' => 'text', 'text' => 'Hello.']], 'end_turn'));
        $this->post('/rekhu/assistant/visitor/chat', ['message' => 'Hi'], $this->headers(['X-Rekhu-Visitor' => self::VISITOR]))->streamedContent();
        Http::assertNotSent(fn (Sent $r) => str_contains(json_encode($r['tools'] ?? []), 'seshat_'));
    }

    public function test_the_cards_speak_the_users_language(): void
    {
        $this->answers($this->streamed([['type' => 'text', 'text' => 'Welk formaat?']], 'end_turn'));
        $first = $this->events($this->actingAs($this->user())->withHeaders($this->headers())
            ->post('/rekhu/assistant/chat', ['message' => 'Controleer mijn flyer', 'locale' => 'nl-NL', 'files' => [UploadedFile::fake()->createWithContent('flyer.pdf', "%PDF-1.7\nflyer")]])->streamedContent());
        $start = collect($first)->firstWhere('type', 'start');
        $this->answers(
            $this->streamed([['type' => 'tool_use', 'id' => 'tu_1', 'name' => 'seshat_check', 'input' => ['file' => $start['files'][0]['id'], 'order' => ['width_mm' => 148, 'height_mm' => 210]]]], 'tool_use'),
            $this->streamed([['type' => 'text', 'text' => 'Klaar.']], 'end_turn'),
        );
        $events = $this->events($this->actingAs($this->user())->withHeaders($this->headers())
            ->post('/rekhu/assistant/chat', ['message' => 'A5', 'locale' => 'nl-NL', 'conversation' => $start['conversation']])->streamedContent());
        $card = collect($events)->firstWhere('kind', 'seshat');
        $this->assertSame(['label' => 'Geen afloop', 'value' => 'Seshat herstelt het'], $card['data']['rows'][0]);
        $this->assertSame('Herstel het', $card['data']['buttons'][0]['label']);
        $this->assertSame('en', app()->getLocale(), 'the app keeps its own language');
    }

    public function test_an_unknown_attachment_is_said_plainly(): void
    {
        $this->answers(
            $this->streamed([['type' => 'tool_use', 'id' => 'tu_1', 'name' => 'seshat_check', 'input' => ['file' => 'att_nope']]], 'tool_use'),
            $this->streamed([['type' => 'text', 'text' => 'Please attach it.']], 'end_turn'),
        );
        $this->actingAs($this->user())->withHeaders($this->headers())->post('/rekhu/assistant/chat', ['message' => 'Check it'])->streamedContent();
        Http::assertSent(fn (Sent $r) => str_contains(json_encode($r['messages'] ?? []), 'no attached file with that id'));
        $this->assertNotContains('/v1/knowers/seshat/check', $this->knowerCalls);
    }
}
