<?php

declare(strict_types=1);

namespace Rekhu\Assistant\Events;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * One answer of the assistant is done, with what it used: the model, the tokens of every model call it took, how many
 * calls, and the actions it ran. Rekhu meters the same calls for your account; listen to this event to charge your own
 * customers per answer, keep a usage table, or count against a plan.
 *
 *     Event::listen(AnswerCompleted::class, fn (AnswerCompleted $e) => Usage::record($e->tenant['id'], $e->model, $e->inputTokens, $e->outputTokens));
 *
 * It fires for every answer that reached the model, also one that failed halfway ($failed true): those calls were made.
 */
final class AnswerCompleted
{
    use Dispatchable;

    /**
     * @param  ?Authenticatable  $user  the signed-in user, or null for a visitor of the public chat
     * @param  ?string  $visitor  the visitor's anonymous id in the public chat
     * @param  array{id: string, name: string}  $tenant  the customer the user belongs to (RekhuAssistant::tenantUsing())
     * @param  ?string  $scope  the chat it was asked in (config scopes), null for the main chat
     * @param  ?string  $model  the model that answered, by its name in the Rekhu account
     * @param  list<string>  $actions  the actions it ran or asked to confirm, in order
     */
    public function __construct(
        public readonly ?Authenticatable $user,
        public readonly ?string $visitor,
        public readonly array $tenant,
        public readonly string $conversation,
        public readonly ?string $answer,
        public readonly ?string $scope,
        public readonly ?string $model,
        public readonly int $inputTokens,
        public readonly int $outputTokens,
        public readonly int $cacheReadTokens,
        public readonly int $cacheWriteTokens,
        public readonly int $calls,
        public readonly array $actions,
        public readonly ?string $stopReason,
        public readonly int $milliseconds,
        public readonly bool $failed = false,
        /** What the actions used of their own (a preflight check, an image improved): [['action', 'kind', 'units', 'meta'?]] */
        public readonly array $actionUsage = [],
    ) {}

    /** Every token the answer took, read from the cache and written to it included. */
    public function totalTokens(): int
    {
        return $this->inputTokens + $this->outputTokens + $this->cacheReadTokens + $this->cacheWriteTokens;
    }
}
