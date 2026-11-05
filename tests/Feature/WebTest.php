<?php

declare(strict_types=1);

namespace Rekhu\Assistant\Tests\Feature;

use Illuminate\Support\Facades\Http;
use Rekhu\Assistant\Runtime\Web;
use Rekhu\Assistant\Tests\TestCase;

/** The web lookups: only the allowed sites, only reading, never inside the network, within the limits. */
final class WebTest extends TestCase
{
    private function settings(array $web = []): array
    {
        config()->set('rekhu-assistant.web', array_replace(['enabled' => true, 'sites' => ['93.184.215.14', '*.paper.test'], 'search' => true, 'per_answer' => 2, 'per_day' => 3], $web));

        return Web::settings(null, false);
    }

    public function test_it_is_off_without_sites_and_for_visitors(): void
    {
        $this->assertFalse($this->settings(['sites' => []])['enabled']);
        config()->set('rekhu-assistant.web', ['enabled' => true, 'sites' => ['93.184.215.14']]);
        $this->assertFalse(Web::settings(null, true)['enabled'], 'visitors only with guests.web');
        $this->assertSame([], Web::tools(Web::settings(null, true)));
    }

    public function test_the_search_is_limited_to_the_sites_and_the_lookups_per_answer(): void
    {
        $tools = Web::tools($this->settings());
        $search = collect($tools)->firstWhere('name', 'web_search');
        $this->assertSame(['93.184.215.14', 'paper.test'], $search['allowed_domains']);
        $this->assertSame(2, $search['max_uses']);
        $this->assertNotNull(collect($tools)->firstWhere('name', 'web_read'));
    }

    public function test_sites_match_exactly_or_as_a_wildcard(): void
    {
        $sites = ['www.antalis.nl', '*.paper.test'];
        $this->assertTrue(Web::allowed('www.antalis.nl', $sites));
        $this->assertFalse(Web::allowed('evil-antalis.nl', $sites));
        $this->assertTrue(Web::allowed('shop.paper.test', $sites));
        $this->assertTrue(Web::allowed('paper.test', $sites));
        $this->assertFalse(Web::allowed('paper.test.evil.com', $sites));
    }

    public function test_addresses_inside_the_network_are_never_read(): void
    {
        foreach (['127.0.0.1', '10.0.0.5', '192.168.1.1', '169.254.169.254', '0.0.0.0'] as $ip) {
            $this->assertFalse(Web::publicHost($ip), $ip);
        }
        $web = $this->settings(['sites' => ['127.0.0.1']]);
        $this->assertSame('127.0.0.1 is not a public address', Web::read('http://127.0.0.1/admin', $web)['error']);
    }

    public function test_only_allowed_sites_plain_pages_and_redirects_that_stay_allowed(): void
    {
        $web = $this->settings();
        $this->assertStringContainsString('is not one of the sites', Web::read('https://www.other.test/', $web)['error']);
        $this->assertStringContainsString('plain http(s)', Web::read('file:///etc/passwd', $web)['error']);
        $this->assertStringContainsString('plain http(s)', Web::read('https://user:pass@93.184.215.14/', $web)['error']);
        $this->assertStringContainsString('usual web ports', Web::read('https://93.184.215.14:8080/', $web)['error']);

        Http::fake([
            'https://93.184.215.14/away' => Http::response('', 302, ['Location' => 'https://169.254.169.254/latest/meta-data']),
            'https://93.184.215.14/paper' => Http::response('<html><title>Papers</title><style>x{}</style><script>alert(1)</script><p>Biotop 90 g/m², 1000 sheets</p></html>', 200, ['Content-Type' => 'text/html']),
            'https://93.184.215.14/file.zip' => Http::response('PK', 200, ['Content-Type' => 'application/zip']),
        ]);
        $this->assertStringContainsString('is not one of the sites', Web::read('https://93.184.215.14/away', $web)['error'], 'a redirect elsewhere is refused');
        $page = Web::read('https://93.184.215.14/paper', $web);
        $this->assertSame('Papers', $page['result']['title']);
        $this->assertStringContainsString('Biotop 90 g/m², 1000 sheets', $page['result']['text']);
        $this->assertStringNotContainsString('alert', $page['result']['text']);
        $this->assertStringContainsString('not a page to read', Web::read('https://93.184.215.14/file.zip', $web)['error']);
        Http::assertNotSent(fn ($r) => $r->hasHeader('Cookie') || $r->hasHeader('Authorization'));
    }

    public function test_the_lookups_of_a_day_run_out(): void
    {
        $web = $this->settings();
        $this->assertTrue(Web::allow('user:7', $web));
        $this->assertTrue(Web::allow('user:7', $web));
        $this->assertTrue(Web::allow('user:7', $web));
        $this->assertFalse(Web::allow('user:7', $web));
        $this->assertTrue(Web::allow('user:8', $web), 'per user');
    }
}
