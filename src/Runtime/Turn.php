<?php

namespace Rekhu\Assistant\Runtime;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Rekhu\Assistant\Catalogue\Actions;
use Rekhu\Assistant\Catalogue\Pages;
use Rekhu\Assistant\Http\Requests\ChatRequest;

/**
 * One turn of the conversation: the user's words (or their click on a confirm button) in, the model's answer out, with
 * the actions, pages and help it used along the way. Events are handed to $emit as they happen:
 *   text {text}  ·  step {label}  ·  card {kind: page|confirm|done|failed|file, …}  ·  debug {action, input, ok, …}
 * Every question, action and answer is written to the chat log; debug events go only to signed-in users, and only
 * when debug is on (by default in the local environment).
 */
class Turn
{
    public const RESULT_LIMIT = 30000;

    private array $actions;

    private array $pages;

    private array $help;

    private bool $debug;

    private ?string $userRef;

    /** $visitor: the anonymous id of a visitor in the public chat; they have no user and only the guest actions. */
    public function __construct(private Request $request, private Conversation $conversation, private Closure $emit, private ?string $visitor = null)
    {
        $user = $visitor === null ? $request->user() : null;
        $this->actions = Actions::for($user);
        $this->pages = Pages::for($user);
        $this->help = Help::topics();
        $this->userRef = $user ? (string) $user->getAuthIdentifier() : null;
        $this->debug = $visitor === null && (bool) (config('rekhu-assistant.debug') ?? app()->environment('local'));
    }

    /** Returns the id of the answer when it has words the user sees, else null. */
    public function turn(string $text, ?string $confirm = null, ?string $browser = null): ?string
    {
        if ($confirm !== null) {
            $this->confirmed($confirm);
        } else {
            $this->conversation->messages[] = ['role' => 'user', 'content' => Str::limit(trim($text), ChatRequest::maxMessage($this->visitor !== null), ''), 'id' => $this->conversation->nextId()];
            ChatLog::question($this->conversation, trim($text), $this->userRef, $this->visitor);
        }
        $answer = $this->conversation->nextId();
        $user = $this->visitor === null ? $this->request->user() : null;
        $platform = new Platform($user, $this->conversation->id, $this->visitor);
        $system = Prompt::system($user, $this->pages, $this->help, $browser, $this->visitor !== null);
        $tools = $this->tools();
        $steps = $this->visitor === null ? (int) config('rekhu-assistant.max_steps', 8) : (int) config('rekhu-assistant.guests.max_steps', 4);

        $stop = null;
        $chars = 0;
        $calls = 0;
        for ($step = 0; $step < $steps; $step++) {
            $calls++;
            $message = $platform->message([
                'model' => config('rekhu-assistant.model'),
                'max_tokens' => (int) config('rekhu-assistant.max_tokens', 4000),
                'system' => $system,
                'tools' => $tools,
                'messages' => self::cached($this->conversation->forModel()),
            ], fn ($delta) => ($this->emit)(['type' => 'text', 'text' => $delta]));
            $this->conversation->messages[] = ['role' => 'assistant', 'content' => $message['content'], 'id' => $answer];
            $stop = $message['stop_reason'];
            $chars += array_sum(array_map(fn ($block) => ($block['type'] ?? '') === 'text' ? mb_strlen((string) $block['text']) : 0, $message['content']));
            if ($stop !== 'tool_use') {
                break;
            }
            $results = [];
            foreach ($message['content'] as $block) {
                if (($block['type'] ?? '') !== 'tool_use') {
                    continue;
                }
                $started = hrtime(true);
                $outcome = $this->call($block['name'], (array) $block['input']);
                $this->trace($block['name'], (array) $block['input'], $outcome, $started);
                $results[] = [
                    'type' => 'tool_result',
                    'tool_use_id' => $block['id'],
                    'content' => Shape::json(Shape::fit($outcome['ok'] ? $outcome['result'] : array_filter(['error' => $outcome['error'], 'errors' => $outcome['errors'] ?? null]), self::RESULT_LIMIT)),
                ] + ($outcome['ok'] ? [] : ['is_error' => true]);
            }
            $this->conversation->messages[] = ['role' => 'user', 'content' => $results];
        }
        $this->conversation->save();
        ChatLog::answer($this->conversation, $calls, $chars, $stop, $this->userRef, $this->visitor);

        return $this->conversation->answered($answer) ? $answer : null;
    }

    /** The action in the chat log, and for a developer in debug mode under the answer: its input, outcome and time. */
    private function trace(string $name, array $input, array $outcome, int $started): void
    {
        $ms = (int) round((hrtime(true) - $started) / 1e6);
        ChatLog::action($this->conversation, $name, $input, $outcome, $ms, $this->userRef, $this->visitor);
        if (! $this->debug) {
            return;
        }
        ($this->emit)(array_filter([
            'type' => 'debug',
            'action' => $name,
            'input' => (object) $input,
            'ok' => $outcome['ok'],
            'ms' => $ms,
            'error' => $outcome['ok'] ? null : $outcome['error'],
            'errors' => $outcome['ok'] ? null : ($outcome['errors'] ?? null),
            'result' => $outcome['ok'] ? Str::limit(Shape::json(Shape::fit($outcome['result'], self::RESULT_LIMIT)), 2000) : null,
        ], fn ($v) => $v !== null));
    }

    /** The user clicked confirm: the action runs now with exactly the input they saw, and the model is told the outcome. */
    private function confirmed(string $id): void
    {
        $pending = $this->conversation->pending[$id] ?? null;
        unset($this->conversation->pending[$id]);
        $action = $pending ? ($this->actions[$pending['tool']] ?? null) : null;
        if (! $action) {
            $this->conversation->report('I clicked confirm, but that action is no longer available.');

            return;
        }
        ($this->emit)(['type' => 'step', 'label' => $action['label'] ?? __('rekhu-assistant::messages.working')]);
        $started = hrtime(true);
        $outcome = Dispatcher::run($action, $pending['input'], $this->request);
        $this->trace($pending['tool'], $pending['input'], $outcome, $started);
        $this->card($action, $outcome);
        $this->conversation->report('I confirmed "'.$action['description'].'". '
            .($outcome['ok'] ? 'It ran. The result: '.Shape::json(Shape::fit($outcome['result'], self::RESULT_LIMIT))
                : 'It failed: '.json_encode(array_filter(['error' => $outcome['error'], 'errors' => $outcome['errors'] ?? null]), JSON_UNESCAPED_UNICODE)));
    }

    private function call(string $name, array $input): array
    {
        if ($name === 'open_page') {
            return $this->openPage($input);
        }
        if ($name === 'help') {
            ($this->emit)(['type' => 'step', 'label' => __('rekhu-assistant::messages.help')]);
            $found = Help::search((string) ($input['question'] ?? ''));

            return ['ok' => true, 'result' => $found ? ['topics' => $found] : ['topics' => [], 'note' => 'nothing in the help about this']];
        }
        $action = $this->actions[$name] ?? null;
        if (! $action) {
            return ['ok' => false, 'error' => 'there is no such action for this user'];
        }
        if ($action['confirm']) {
            $id = Conversation::confirmId($name, $input);
            $this->conversation->pending[$id] = ['tool' => $name, 'input' => $input];
            // the card shows what the user filled in, not the record ids in the address (the answer names the record)
            $shown = isset($action['route']) ? array_diff_key($input, array_flip($action['route']->parameterNames())) : $input;
            ($this->emit)(['type' => 'card', 'kind' => 'confirm', 'id' => $id, 'title' => $action['label'] ?? $action['description'], 'input' => (object) $shown, 'danger' => $action['verb'] === 'DELETE']);

            return ['ok' => true, 'result' => ['status' => 'needs_confirmation', 'note' => 'The user sees a confirm button for exactly this input. Nothing has happened yet.']];
        }
        ($this->emit)(['type' => 'step', 'label' => $action['label'] ?? __('rekhu-assistant::messages.working')]);
        $outcome = Dispatcher::run($action, $input, $this->request);
        $this->card($action, $outcome);

        return $outcome;
    }

    private function openPage(array $input): array
    {
        $page = $this->pages[(string) ($input['page'] ?? '')] ?? null;
        if (! $page) {
            return ['ok' => false, 'error' => 'no such page for this user; pick one from the list'];
        }
        try {
            $url = ! empty($page['frontend']) ? $this->frontendUrl($page, (array) ($input['parameters'] ?? [])) : route($page['name'], (array) ($input['parameters'] ?? []));
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => 'the page needs: '.implode(', ', $page['parameters'])];
        }
        ($this->emit)(['type' => 'card', 'kind' => 'page', 'label' => (string) ($input['label'] ?? $page['label']), 'url' => $url]);

        return ['ok' => true, 'result' => ['status' => 'shown', 'note' => 'The user has a button to open it.']];
    }

    /** A front end's page: its address with the placeholders filled, at the front end's own URL. */
    private function frontendUrl(array $page, array $parameters): string
    {
        $missing = array_diff($page['parameters'], array_keys(array_filter($parameters, fn ($v) => $v !== null && $v !== '')));
        if ($missing) {
            throw new \InvalidArgumentException('missing '.implode(', ', $missing));
        }
        $path = preg_replace_callback('/\{(\w+)\??\}/', fn ($m) => rawurlencode((string) $parameters[$m[1]]), $page['uri']);
        $base = rtrim((string) (config('rekhu-assistant.pages.frontend.url') ?: config('app.url')), '/');

        return $base.$path;
    }

    private function card(array $action, array $outcome): void
    {
        // a file: a download button, whatever kind of action made it
        if ($outcome['ok'] && ($outcome['result']['status'] ?? null) === 'file') {
            ($this->emit)(['type' => 'card', 'kind' => 'file', 'title' => $action['label'] ?? $outcome['result']['name'], 'name' => $outcome['result']['name'], 'url' => $outcome['result']['url'], 'size' => $outcome['result']['size']]);

            return;
        }
        // reading needs no card: the answer says what was found
        if ($outcome['ok'] && $action['verb'] === 'GET') {
            return;
        }
        ($this->emit)($outcome['ok']
            ? array_filter(['type' => 'card', 'kind' => 'done', 'title' => $action['label'] ?? $action['description'], 'message' => $outcome['result']['message'] ?? null, 'url' => $outcome['result']['page'] ?? null])
            : ['type' => 'card', 'kind' => 'failed', 'title' => $action['label'] ?? $action['description'], 'message' => $outcome['error']]);
    }

    private function tools(): array
    {
        $tools = array_values(array_map(fn ($a) => ['name' => $a['name'], 'description' => $a['description'].($a['confirm'] ? ' (The user confirms before it runs.)' : ''), 'input_schema' => $a['schema']], $this->actions));
        if ($this->pages) {
            $tools[] = ['name' => 'open_page', 'description' => 'Gives the user a button that opens a page of the application. "page" is a name from the page list; "parameters" fills the {placeholders} in its address.', 'input_schema' => [
                'type' => 'object',
                'properties' => ['page' => ['type' => 'string', 'enum' => array_keys($this->pages)], 'parameters' => ['type' => 'object'], 'label' => ['type' => 'string', 'description' => 'The button text, short']],
                'required' => ['page'],
            ]];
        }
        if ($this->help) {
            $tools[] = ['name' => 'help', 'description' => 'Searches the application\'s help for how something works or how to do it.', 'input_schema' => [
                'type' => 'object', 'properties' => ['question' => ['type' => 'string']], 'required' => ['question'],
            ]];
        }

        return $tools;
    }

    /** The whole conversation is sent every time; a cache mark on its last block makes it a cheap cache read next time. */
    private static function cached(array $messages): array
    {
        if (! $messages) {
            return $messages;
        }
        $last = array_pop($messages);
        $blocks = is_string($last['content']) ? [['type' => 'text', 'text' => $last['content']]] : $last['content'];
        $blocks[count($blocks) - 1]['cache_control'] = ['type' => 'ephemeral'];
        $messages[] = ['role' => $last['role'], 'content' => $blocks];

        return $messages;
    }
}
