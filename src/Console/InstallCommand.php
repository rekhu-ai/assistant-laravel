<?php

namespace Rekhu\Assistant\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

/** Asks for the app key, checks it with Rekhu, writes it to .env and publishes the config. */
class InstallCommand extends Command
{
    protected $signature = 'rekhu:assistant-install {--key= : the app key from the Rekhu account} {--platform= : a test platform instead of https://api.rekhu.ai}';

    protected $description = 'Connect this app to its assistant on Rekhu';

    public function handle(): int
    {
        $key = trim((string) ($this->option('key') ?: $this->secret('The app key (Rekhu account → Products → Rekhu Assistant → Add app)')));
        if (! preg_match('/^agk_([a-z0-9-]+)_([a-z0-9-]+)_[A-Za-z0-9_-]+$/', $key, $m)) {
            $this->error('That is not a Rekhu app key (it starts with agk_).');

            return self::FAILURE;
        }
        $platform = rtrim((string) ($this->option('platform') ?: config('rekhu-assistant.platform')), '/');
        $response = Http::baseUrl($platform)->timeout(15)->acceptJson()
            ->withHeaders(['x-api-key' => $key, 'x-agent' => $m[2]])->get('/v1/balance');
        if (! $response->successful()) {
            $this->error('Rekhu refused the key ('.$response->status().'): '.($response->json('error.message') ?? 'no reason given'));

            return self::FAILURE;
        }

        $this->writeEnv('REKHU_ASSISTANT_KEY', $key);
        if ($this->option('platform')) {
            $this->writeEnv('REKHU_PLATFORM', $platform);
        }
        $this->callSilently('vendor:publish', ['--tag' => 'rekhu-assistant-config']);
        if (! is_dir(resource_path('rekhu/help'))) {
            @mkdir(resource_path('rekhu/help'), 0755, true);
        }

        $this->info("Connected to Rekhu as {$m[1]} / {$m[2]}.");
        $this->line('Next:');
        $this->line('  1. Put <x-rekhu-assistant /> in your layout, before </body>.');
        $this->line('  2. Mark the actions it may perform: #[\Rekhu\Assistant\Attributes\Tool(\'What it does\')] on a controller method.');
        $this->line('  3. Write help topics as markdown in resources/rekhu/help.');
        $this->line('  4. php artisan rekhu:assistant shows what it sees.');

        return self::SUCCESS;
    }

    private function writeEnv(string $name, string $value): void
    {
        $path = base_path('.env');
        $env = is_file($path) ? (string) file_get_contents($path) : '';
        $line = $name.'='.$value;
        $env = preg_match('/^'.preg_quote($name, '/').'=.*$/m', $env)
            ? preg_replace('/^'.preg_quote($name, '/').'=.*$/m', $line, $env)
            : rtrim($env)."\n\n".$line."\n";
        file_put_contents($path, $env);
    }
}
