<?php

namespace Rekhu\Assistant\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Rekhu\Assistant\Catalogue\ActionsFile;
use Rekhu\Assistant\Catalogue\Outline;
use Rekhu\Assistant\RekhuAssistant;
use Throwable;

/**
 * php artisan rekhu:assistant:scan: reads the app's routes (never its records), lets Rekhu propose which the assistant
 * should offer and how each is described, and writes assistant/actions.php for the developer to decide. Only routes the
 * file does not have yet are sent, so a scan after a change costs little. The written file is kept in the Rekhu account
 * per environment, and Rekhu drafts the project's knowledge from it for the team to approve there.
 */
class ScanCommand extends Command
{
    protected $signature = 'rekhu:assistant:scan
        {--only= : only routes whose name or address matches, such as "orders.*" or "api/orders*"}
        {--no-code : do not send the code of the controller methods, only routes and inputs}
        {--no-knowledge : do not draft the project knowledge}
        {--dry-run : show what would change, write nothing}
        {--restore : write back the last file kept in the Rekhu account for this environment}
        {--force : with --restore, replace a file that is there}';

    protected $description = 'Let Rekhu read the app and propose the assistant\'s actions in assistant/actions.php';

    private const BATCH = 40;

    public function handle(): int
    {
        $key = RekhuAssistant::key();
        if (! $key) {
            $this->error('REKHU_ASSISTANT_KEY is missing or not a Rekhu app key. Run php artisan rekhu:assistant-install first.');

            return self::FAILURE;
        }
        if ($this->option('restore')) {
            return $this->restore();
        }

        $outline = Outline::routes(! $this->option('no-code'));
        if ($only = $this->option('only')) {
            $outline = array_values(array_filter($outline, fn ($r) => Str::is($only, $r['key']) || Str::is($only, $r['uri'])));
        }
        $current = ActionsFile::all();
        $todo = array_values(array_filter($outline, fn ($r) => ! array_key_exists($r['key'], $current)));
        $this->line('<options=bold>'.count($outline).' routes</> the assistant could offer'.(ActionsFile::exists() ? ', '.count($todo).' not in '.$this->relative().' yet' : '').'.');
        $this->line('  Sent to Rekhu: routes, their inputs'.($this->option('no-code') ? '' : ' and the code of their controller methods').'. No records of your app.');

        $proposal = [];
        $questions = [];
        foreach (array_chunk($todo, self::BATCH) as $i => $batch) {
            $this->line('  Proposing '.($i * self::BATCH + 1).' to '.($i * self::BATCH + count($batch)).' of '.count($todo).'…');
            try {
                $answer = $this->post('/v1/assistant/scan', [
                    'app' => ['name' => (string) config('app.name'), 'locales' => array_values(array_unique([app()->getLocale(), (string) config('app.fallback_locale')]))],
                    'routes' => array_map(fn ($r) => array_diff_key($r, ['route' => true]), $batch),
                ], 180);
            } catch (Throwable $e) {
                $this->error('  '.$e->getMessage());

                return self::FAILURE;
            }
            $proposal += (array) ($answer['actions'] ?? []);
            $questions = [...$questions, ...(array) ($answer['questions'] ?? [])];
        }

        // gone means the route itself is gone: a route that has #[Tool] by now is still there (check reports the double)
        $present = array_map(fn ($route) => ActionsFile::key($route), Route::getRoutes()->getRoutes());
        $merged = ActionsFile::merge($current, $proposal, $present);
        $on = count(array_filter($merged['added'], fn ($k) => ($merged['entries'][$k]['proposed'] ?? null) === 'on'));
        $this->newLine();
        $this->line('  <fg=green>'.count($merged['added']).' new</> ('.$on.' Rekhu would switch on, all off until you decide), '.count($merged['gone']).' gone, '.$merged['kept'].' kept as you left them.');
        foreach (array_slice($merged['added'], 0, 30) as $k) {
            $e = $merged['entries'][$k];
            $this->line('    '.(($e['proposed'] ?? null) === 'on' ? '<fg=green>+</>' : '<fg=gray>·</>').' '.ActionsFile::label($k).(($e['proposed'] ?? null) === 'on' ? '' : '  <fg=gray>'.($e['why_off'] ?? '').'</>'));
        }
        if (count($merged['added']) > 30) {
            $this->line('    … and '.(count($merged['added']) - 30).' more in the file.');
        }
        foreach ($merged['gone'] as $k) {
            $this->line('    <fg=yellow>−</> '.ActionsFile::label($k).'  <fg=gray>route no longer there</>');
        }

        if ($this->option('dry-run')) {
            $this->newLine();
            $this->line('Dry run: nothing written.');

            return self::SUCCESS;
        }
        ActionsFile::write($merged['entries']);
        $this->writeQuestions($questions);

        try {
            $kept = $this->post('/v1/assistant/scan/finish', [
                'app' => ['name' => (string) config('app.name')],
                'actions' => $merged['entries'],
                'knowledge' => ! $this->option('no-knowledge'),
            ], 120);
            $this->line('  Kept in your Rekhu account for this environment'.(($kept['knowledge_draft'] ?? false) ? '; a draft of the project knowledge waits there for approval (Assistant tab).' : '.'));
        } catch (Throwable $e) {
            $this->warn('  The file is written, but Rekhu could not keep it: '.$e->getMessage());
        }

        $this->newLine();
        $this->line('Next: open '.$this->relative().', switch on what the assistant may do (change on to true), then');
        $this->line('  php artisan rekhu:assistant:check       what is unclear or unsafe');
        $this->line('  php artisan rekhu:assistant:test --user=ID   ask the questions in assistant/questions.php');

        return self::SUCCESS;
    }

    private function restore(): int
    {
        if (ActionsFile::exists() && ! $this->option('force')) {
            $this->error($this->relative().' is there. Give --force to replace it with the last one kept in Rekhu.');

            return self::FAILURE;
        }
        try {
            $latest = $this->get('/v1/assistant/scan/latest');
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
        ActionsFile::write((array) ($latest['actions'] ?? []));
        $this->info('Written '.$this->relative().' from the scan of '.($latest['at'] ?? 'before').': '.($latest['offered'] ?? 0).' of '.($latest['total'] ?? 0).' actions on.');

        return self::SUCCESS;
    }

    /** assistant/questions.php: a question per action, for rekhu:assistant:test; questions already there stay. */
    private function writeQuestions(array $questions): void
    {
        $path = dirname(ActionsFile::path()).'/questions.php';
        $current = is_file($path) ? (array) ((static fn () => require $path)()) : [];
        $seen = array_flip(array_map(fn ($q) => (string) ($q['question'] ?? ''), $current));
        foreach ($questions as $q) {
            if (! isset($seen[$q['question'] ?? ''])) {
                $current[] = ['question' => (string) $q['question'], 'action' => (string) $q['action']];
            }
        }
        $out = "<?php\n\n// Questions a user would ask, with the action the assistant should use for each: php artisan rekhu:assistant:test\n";
        $out .= "// asks them all. Add your own; an answer can also be checked for words with 'says' => '…'.\n\nreturn [\n";
        foreach ($current as $q) {
            $out .= '    ['.implode(', ', array_map(fn ($k, $v) => var_export($k, true).' => '.var_export($v, true), array_keys($q), $q))."],\n";
        }
        file_put_contents($path, $out."];\n");
    }

    private function relative(): string
    {
        return Str::after(ActionsFile::path(), base_path().'/');
    }

    private function request()
    {
        $key = RekhuAssistant::key();
        $tenant = RekhuAssistant::tenant(null);

        return Http::baseUrl(rtrim((string) config('rekhu-assistant.platform'), '/'))->acceptJson()->withHeaders([
            'x-api-key' => $key['key'], 'x-agent' => $key['agent'], 'x-tenant' => (string) $tenant['id'], 'x-tenant-name' => (string) $tenant['name'],
            'x-user' => 'scan', 'x-session' => 'assistant-scan', 'user-agent' => 'rekhu-assistant-laravel/'.RekhuAssistant::VERSION,
        ]);
    }

    private function post(string $path, array $body, int $timeout): array
    {
        $response = $this->request()->timeout($timeout)->post($path, $body);
        if (! $response->successful()) {
            throw new \RuntimeException('Rekhu answered '.$response->status().': '.($response->json('error.message') ?? 'no reason given'));
        }

        return (array) $response->json();
    }

    private function get(string $path): array
    {
        $response = $this->request()->timeout(30)->get($path);
        if (! $response->successful()) {
            throw new \RuntimeException('Rekhu answered '.$response->status().': '.($response->json('error.message') ?? 'no reason given'));
        }

        return (array) $response->json();
    }
}
