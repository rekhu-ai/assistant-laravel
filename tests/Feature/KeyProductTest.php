<?php

declare(strict_types=1);

namespace Rekhu\Assistant\Tests\Feature;

use Illuminate\Support\Facades\Http;
use Rekhu\Assistant\Tests\TestCase;

final class KeyProductTest extends TestCase
{
    public function test_a_key_of_another_product_is_refused_with_where_to_make_the_right_one(): void
    {
        Http::fake();

        $this->artisan('rekhu:assistant-install', ['--key' => 'agk_acme_laravel-dev_abcdefghijklmnop'])
            ->expectsOutputToContain('This key is for laravel-dev, not for Rekhu Assistant')
            ->assertFailed();
        Http::assertNothingSent();
    }

    public function test_the_status_names_a_key_of_another_product(): void
    {
        config(['rekhu-assistant.key' => 'agk_acme_laravel-dev_abcdefghijklmnop']);
        Http::fake(['rekhu.test/*' => Http::response(['prepaid' => true, 'balance' => ['display' => '$5.00']])]);

        $this->artisan('rekhu:assistant')->expectsOutputToContain('This key is for laravel-dev, not for Rekhu Assistant');
    }
}
