<?php

namespace Rekhu\Assistant\Console;

use Illuminate\Console\Command;
use Rekhu\Assistant\Catalogue\Actions;
use Rekhu\Assistant\Support\Rehearsal;

/**
 * php artisan rekhu:assistant:try list_orders --user=12 --locale=ar --input='{"status":"open"}': runs one action as that
 * user, in that language, exactly as the chat does, and shows what the assistant reads of the result.
 */
class TryCommand extends Command
{
    protected $signature = 'rekhu:assistant:try
        {action? : the action\'s name; leave it out to list them}
        {--user= : the id of the user to run it as}
        {--locale= : the language to run it in, such as ar or en}
        {--input= : the input as JSON, such as {"search":"Ada"}}
        {--full : show the whole result, not only what the assistant reads}';

    protected $description = 'Run one assistant action as a user and see what the assistant gets back';

    public function handle(): int
    {
        $user = Rehearsal::user($this->option('user'));
        if ($this->option('user') && ! $user) {
            $this->error('No user '.$this->option('user').'.');

            return self::FAILURE;
        }
        $actions = Actions::for($user);
        $name = (string) $this->argument('action');
        if ($name === '') {
            $this->line('Actions '.($user ? 'of user '.$user->getAuthIdentifier() : 'for visitors').': '.implode(', ', array_keys($actions)));
            $this->line('Run one with: php artisan rekhu:assistant:try <name> --user=ID [--locale=ar] [--input=\'{…}\']');

            return self::SUCCESS;
        }
        $action = $actions[$name] ?? null;
        if (! $action) {
            $exists = isset(Actions::for($user, all: true)[$name]);
            $this->error($exists
                ? "$name is not offered to ".($user ? 'user '.$user->getAuthIdentifier() : 'visitors').': its route\'s can: middleware, RekhuAssistant::authorizeUsing() or the Tool class\'s authorize() refuses it.'
                : "There is no action $name. These are: ".implode(', ', array_keys(Actions::for($user, all: true))));

            return self::FAILURE;
        }
        $input = json_decode((string) ($this->option('input') ?: '{}'), true);
        if (! is_array($input)) {
            $this->error('--input is not JSON: '.json_last_error_msg());

            return self::FAILURE;
        }

        $locale = $this->option('locale') ?: null;
        $this->line("<options=bold>$name</> as ".($user ? 'user '.$user->getAuthIdentifier() : 'a visitor').($locale ? ", in $locale" : '')
            .' · '.(isset($action['class']) ? $action['class'] : $action['verb'].' /'.$action['uri']));
        $this->line('Input  '.json_encode((object) $input, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        $run = Rehearsal::run($action, $input, $user, $locale);
        $outcome = $run['outcome'];
        if (! $outcome['ok']) {
            $this->error("Refused in {$run['ms']} ms: {$outcome['error']}");
            if (! empty($outcome['errors'])) {
                $this->line(json_encode($outcome['errors'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            }
            $this->line('This is what the assistant reads, and passes on to the user in its own words.');

            return self::FAILURE;
        }
        $read = strlen($run['read']);
        $this->info("OK in {$run['ms']} ms · ".number_format($run['size']).' characters'.($read < $run['size'] ? ', of which the assistant reads '.number_format($read) : ', all of it read'));
        $shown = $this->option('full') ? json_decode(json_encode($outcome['result']), true) : json_decode($run['read'], true);
        if (is_array($shown) && isset($shown['note'])) {
            $this->warn('Note for the assistant: '.$shown['note']);
        }
        $this->line(json_encode($shown, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
    }
}
