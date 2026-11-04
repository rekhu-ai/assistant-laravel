<?php

namespace Rekhu\Assistant\Console;

use Illuminate\Console\Command;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Rekhu\Assistant\Catalogue\ActionsFile;
use Rekhu\Assistant\Runtime\Conversation;
use Rekhu\Assistant\Runtime\Turn;
use Rekhu\Assistant\Support\Rehearsal;
use Throwable;

/**
 * php artisan rekhu:assistant:test --user=12: asks the questions in assistant/questions.php as that user, each in a new
 * conversation, exactly as the chat does, and checks that the assistant used the expected action (and, with "says",
 * that the answer has those words). Every question is a real model call, charged like a chat message. An action that
 * needs the user's click is counted as used when the assistant asked for it; nothing that changes data runs.
 */
class TestCommand extends Command
{
    protected $signature = 'rekhu:assistant:test
        {--user= : the id of the user to ask as}
        {--locale= : the language to ask in, such as ar}
        {--only= : only questions whose expected action matches, such as "orders.*"}';

    protected $description = 'Ask the questions in assistant/questions.php and check the assistant uses the right action';

    public function handle(): int
    {
        $user = Rehearsal::user($this->option('user'));
        if (! $user) {
            $this->error('Give --user=ID: the questions are asked as that user, with their rights.');

            return self::FAILURE;
        }
        $path = dirname(ActionsFile::path()).'/questions.php';
        $questions = is_file($path) ? (array) ((static fn () => require $path)()) : [];
        if ($only = $this->option('only')) {
            $questions = array_values(array_filter($questions, fn ($q) => Str::is($only, (string) ($q['action'] ?? ''))));
        }
        if (! $questions) {
            $this->error('No questions in '.Str::after($path, base_path().'/').'. php artisan rekhu:assistant:scan writes some; add your own as [\'question\' => …, \'action\' => …].');

            return self::FAILURE;
        }
        if ($this->option('locale')) {
            app()->setLocale((string) $this->option('locale'));
        }
        $this->line('<options=bold>Asking '.count($questions).' questions</> as user '.$user->getAuthIdentifier().'. Each one is a model call.');
        $passed = 0;
        foreach ($questions as $q) {
            $question = (string) ($q['question'] ?? '');
            $expected = array_map(fn ($a) => self::tool((string) $a), (array) ($q['action'] ?? []));
            try {
                [$used, $text] = $this->askAs($user, $question);
            } catch (Throwable $e) {
                $this->line('  <fg=red>✗</> '.$question);
                $this->line('      the turn failed: '.$e->getMessage());

                continue;
            }
            $usedTools = array_map(fn ($n) => self::tool($n), $used);
            $okAction = ! $expected || array_intersect($expected, $usedTools);
            $says = isset($q['says']) ? (array) $q['says'] : [];
            $okSays = ! $says || ! array_filter($says, fn ($w) => ! Str::contains(Str::lower($text), Str::lower((string) $w)));
            if ($okAction && $okSays) {
                $passed++;
                $this->line('  <fg=green>✓</> '.$question.'  <fg=gray>'.implode(', ', $used).'</>');

                continue;
            }
            $this->line('  <fg=red>✗</> '.$question);
            if (! $okAction) {
                $this->line('      expected '.implode(' or ', (array) $q['action']).', used '.($used ? implode(', ', $used) : 'no action'));
            }
            if (! $okSays) {
                $this->line('      the answer does not say '.implode(', ', $says).': '.Str::limit(trim($text), 160));
            }
        }
        $this->newLine();
        $failed = count($questions) - $passed;
        $this->line($failed ? "<fg=red>$failed of ".count($questions).' did not pass.</> Make the action\'s description say when to use it, then ask again.' : '<fg=green>All '.count($questions).' passed.</>');

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    /** One question in a new conversation: the actions the assistant asked for, and the text it answered. */
    private function askAs($user, string $question): array
    {
        $request = Request::create('/'.trim((string) config('rekhu-assistant.path', 'rekhu/assistant'), '/').'/chat', 'POST');
        $request->setUserResolver(fn () => $user);
        foreach (array_keys((array) config('auth.guards', [])) as $guard) {
            try {
                Auth::guard($guard)->setUser($user);
            } catch (Throwable) {
            }
        }
        app()->instance('request', $request);
        $conversation = Conversation::open($user, null);
        $text = '';
        $turn = new Turn($request, $conversation, function (array $event) use (&$text) {
            if (($event['type'] ?? '') === 'text') {
                $text .= (string) $event['text'];
            }
        });
        $turn->turn($question);
        $used = [];
        foreach ($conversation->messages as $message) {
            foreach (is_array($message['content'] ?? null) ? $message['content'] : [] as $block) {
                if (($block['type'] ?? '') === 'tool_use' && ! empty($block['name'])) {
                    $used[] = (string) $block['name'];
                }
            }
        }
        $conversation->forget();

        return [array_values(array_unique($used)), $text];
    }

    /** An action as the model names it ("orders.index" → "orders_index"), so the file's keys compare with tool names. */
    private static function tool(string $name): string
    {
        return Str::limit(preg_replace('/[^a-zA-Z0-9_-]+/', '_', $name), 60, '');
    }
}
