<?php

declare(strict_types=1);

namespace Rekhu\Assistant\Console;

use Illuminate\Console\Command;
use Rekhu\Assistant\RekhuAssistant;
use Rekhu\Assistant\Runtime\DatabaseConversations;

/**
 * Removes the conversations kept past conversations.keep_days. The package schedules it once a day by itself; run it by
 * hand after lowering keep_days. The cache store forgets on its own and has nothing to remove.
 */
final class PruneCommand extends Command
{
    protected $signature = 'rekhu-assistant:prune';

    protected $description = 'Remove the assistant\'s conversations that are kept past conversations.keep_days';

    /** Removes what is past its time, and says how many. */
    public function handle(): int
    {
        $store = RekhuAssistant::store();
        if (! $store instanceof DatabaseConversations) {
            $this->info('Conversations are kept in the cache, which forgets them on its own: nothing to remove.');

            return self::SUCCESS;
        }
        $removed = $store->prune();
        $this->info($removed === 1 ? '1 conversation removed.' : "{$removed} conversations removed.");

        return self::SUCCESS;
    }
}
