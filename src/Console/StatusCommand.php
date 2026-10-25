<?php

namespace Rekhu\Assistant\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Rekhu\Assistant\Catalogue\Actions;
use Rekhu\Assistant\Catalogue\Pages;
use Rekhu\Assistant\RekhuAssistant;
use Rekhu\Assistant\Runtime\Help;
use Rekhu\Assistant\Runtime\Reports;

/** What the assistant sees in this app, and whether Rekhu answers. Pages are those a user without roles may open. */
class StatusCommand extends Command
{
    protected $signature = 'rekhu:assistant {--user= : the id of a user to see the actions and pages as}';

    protected $description = 'Show the assistant\'s actions, pages and help, and check the connection to Rekhu';

    public function handle(): int
    {
        $user = null;
        if ($id = $this->option('user')) {
            $model = config('auth.providers.users.model');
            $user = $model::find($id);
            if (! $user) {
                $this->error("No user $id.");

                return self::FAILURE;
            }
        }

        $this->line('<options=bold>Rekhu Assistant</> '.RekhuAssistant::VERSION.($user ? ', as user '.$user->getAuthIdentifier() : ''));
        $key = RekhuAssistant::key();
        if (! $key) {
            $this->warn('  No key. Run php artisan rekhu:assistant-install.');
        } else {
            if ($wrong = RekhuAssistant::wrongProduct($key['agent'])) {
                $this->error('  '.$wrong);
            }
            $response = Http::baseUrl(rtrim((string) config('rekhu-assistant.platform'), '/'))->timeout(15)->acceptJson()
                ->withHeaders(['x-api-key' => $key['key'], 'x-agent' => $key['agent']])->get('/v1/balance');
            if ($response->successful()) {
                $this->info("  Connected: {$key['customer']} / {$key['agent']}");
                InstallCommand::credit($this, (array) $response->json());
            } else {
                $this->error('  Rekhu refused the key ('.$response->status().'): '.($response->json('error.message') ?? ''));
            }
        }

        $this->reports($key);

        $actions = Actions::for($user, ! $user);
        $this->newLine();
        $this->line('<options=bold>Actions</> ('.count($actions).')'.($user ? '' : ' — all of them; give --user to see one user\'s'));
        $this->table(['name', 'route', 'from', 'confirm', 'input', 'description'], array_map(fn ($a) => [
            $a['name'], isset($a['class']) ? $a['class'] : $a['verb'].' /'.$a['uri'], $a['source'], $a['confirm'] ? 'yes' : '',
            implode(', ', array_keys((array) $a['schema']['properties'])), Str::limit($a['description'], 60),
        ], array_values($actions)));

        $pages = Pages::for($user);
        $this->line('<options=bold>Pages</> ('.count($pages).')');
        $this->table(['name', 'address', 'label'], array_map(fn ($p) => [$p['name'], $p['uri'], $p['label']], array_values($pages)));

        $help = Help::topics();
        $this->line('<options=bold>Help</> ('.count($help).') in '.str_replace(base_path().'/', '', RekhuAssistant::helpPath()));
        foreach ($help as $topic) {
            $this->line("  - {$topic['title']}");
        }

        return self::SUCCESS;
    }

    /** Whether users can report answers, and whether Rekhu takes reports (a check that stores nothing). */
    private function reports(?array $key): void
    {
        $this->newLine();
        if (! Reports::enabled()) {
            $this->line('<options=bold>Reports</> off (rekhu-assistant.reports.enabled)');

            return;
        }
        $this->line('<options=bold>Reports</> on: '.implode(', ', Reports::keys()).(Reports::enabled(true) && config('rekhu-assistant.guests.enabled') ? '; visitors too' : ''));
        if (! $key) {
            return;
        }
        $response = Http::baseUrl(rtrim((string) config('rekhu-assistant.platform'), '/'))->timeout(15)->acceptJson()
            ->withHeaders(['x-api-key' => $key['key'], 'x-agent' => $key['agent']])->post('/v1/reports', ['check' => true]);
        // the check is answered without storing anything
        if ($response->successful()) {
            $this->info('  Rekhu takes reports: they show under Reports on the project\'s Assistant tab.');
        } elseif ($response->status() === 404) {
            $this->warn('  The platform does not accept reports yet; users are still thanked and the reports are logged as failed.');
        } else {
            $this->error('  Rekhu refused the report check ('.$response->status().'): '.($response->json('error.message') ?? ''));
        }
    }
}
