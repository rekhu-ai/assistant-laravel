<?php

declare(strict_types=1);

namespace Rekhu\Assistant\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Rekhu\Assistant\Runtime\Platform;
use Throwable;

/** The second try of a report that Rekhu did not take the first time. The key is read when it runs, never queued. */
final class SendReport implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    /** @param  array<string, string>  $who  the customer, user and conversation headers */
    public function __construct(public array $who, public array $report) {}

    public function handle(): void
    {
        try {
            Platform::sendReport($this->who, $this->report);
        } catch (Throwable $e) {
            report($e);
        }
    }
}
