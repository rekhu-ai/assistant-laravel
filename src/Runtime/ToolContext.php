<?php

declare(strict_types=1);

namespace Rekhu\Assistant\Runtime;

use Closure;
use Illuminate\Http\Request;

/** What a Tool class has while it runs: the chat's request, the conversation, a way to show progress, its own usage. */
final class ToolContext
{
    /** @var list<array<string, mixed>> usage the tool reported */
    public array $usage = [];

    public function __construct(
        public readonly Request $request,
        public readonly ?Conversation $conversation,
        public readonly ?Closure $emit = null,
    ) {}

    public function emit(array $event): void
    {
        if ($this->emit) {
            ($this->emit)($event);
        }
    }
}
