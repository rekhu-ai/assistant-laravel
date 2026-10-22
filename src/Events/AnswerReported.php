<?php

declare(strict_types=1);

namespace Rekhu\Assistant\Events;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A user reported a problem with an answer. The report is on its way to the Reports on the project's Assistant tab in the Rekhu account;
 * listen to this event for a copy of your own: a table, a message to your team, a ticket.
 *
 *     Event::listen(AnswerReported::class, fn (AnswerReported $e) => Log::info('assistant report', $e->report));
 */
final class AnswerReported
{
    use Dispatchable;

    /**
     * @param  ?Authenticatable  $user  the signed-in user, or null for a visitor of the public chat
     * @param  ?string  $visitor  the visitor's anonymous id in the public chat
     * @param  array<string, mixed>  $report  what was sent to Rekhu: the question, the answer, the transcript up to it and the actions it used
     */
    public function __construct(
        public readonly ?Authenticatable $user,
        public readonly ?string $visitor,
        public readonly string $conversation,
        public readonly string $message,
        public readonly string $reason,
        public readonly ?string $comment,
        public readonly array $report,
    ) {}
}
