<?php

namespace Rekhu\Assistant\Runtime;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * One user's conversation, kept on the server (the app's cache), never in the browser: the model only ever reads what
 * the app itself recorded. Also holds the actions waiting for the user's confirmation.
 *
 * What the user typed is stored as a string; what the app reports on the user's behalf (a confirmed action and its
 * result) as content blocks, so the transcript shows the first and not the second.
 *
 * Every message the user sees has an id ("m_7"): the user's own message, and the answer to it. An answer that comes
 * in several parts around actions is one answer with one id. Ids come from a counter kept with the conversation, so
 * they stay the same when the oldest turns are trimmed.
 */
final class Conversation
{
    /** The most lines the transcript returns. */
    private const TRANSCRIPT_LINES = 30;

    /** The most characters of one action's result in a report, when results are included at all. */
    private const RESULT_LIMIT = 4000;

    public array $messages = [];

    /** confirm id => ['tool', 'input'] shown to the user and not yet clicked */
    public array $pending = [];

    /** The number of the next message id. */
    private int $next = 1;

    /** Whether the conversation was found in the cache, rather than started now. */
    public bool $exists = false;

    private function __construct(public string $id, private string $owner) {}

    /** The conversation $id of this user, or of this visitor in the public chat (an anonymous id from the widget). */
    public static function open(?Authenticatable $user, ?string $id, ?string $visitor = null): self
    {
        $owner = $user ? get_class($user).':'.$user->getAuthIdentifier() : ($visitor ? 'visitor:'.$visitor : 'guest');
        $id = $id && preg_match('/^[a-zA-Z0-9-]{8,64}$/', $id) ? $id : (string) Str::uuid();
        $conversation = new self($id, $owner);
        $stored = Cache::get($conversation->key());
        // kept as JSON: only strings and arrays, whatever classes the app's cache store may unserialize
        if (is_string($stored)) {
            $stored = json_decode($stored, true);
        }
        if (is_array($stored)) {
            $conversation->exists = true;
            $conversation->messages = $stored['messages'] ?? [];
            $conversation->pending = $stored['pending'] ?? [];
            $conversation->next = (int) ($stored['next'] ?? 1);
            if (! isset($stored['next'])) {
                $conversation->numberStoredMessages();
            }
        }

        return $conversation;
    }

    public function save(): void
    {
        // the oldest turns go first when it grows long; a cut never starts on a tool result without its call
        while (count($this->messages) > 60) {
            array_shift($this->messages);
            while ($this->messages && ($this->messages[0]['role'] !== 'user' || ! is_string($this->messages[0]['content']))) {
                array_shift($this->messages);
            }
        }
        Cache::put($this->key(), json_encode(['messages' => $this->messages, 'pending' => $this->pending, 'next' => $this->next], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE), now()->addMinutes((int) config('rekhu-assistant.conversation_minutes', 1440)));
    }

    /** A new message id. */
    public function nextId(): string
    {
        return 'm_'.$this->next++;
    }

    /** The messages as the model reads them: role and content, without the ids; an action's input is always an object. */
    public function forModel(): array
    {
        return array_map(fn (array $message) => ['role' => $message['role'], 'content' => is_array($message['content'])
            ? array_map(fn ($block) => ($block['type'] ?? '') === 'tool_use' ? ['input' => self::input($block['input'] ?? null)] + $block : $block, $message['content'])
            : $message['content'],
        ], $this->messages);
    }

    /** An action's input as a JSON object: {} when empty, and when an older cache store kept an object it cannot read back. */
    private static function input(mixed $input): array|object
    {
        return is_array($input) && $input !== [] ? $input : (object) [];
    }

    /** @return list<array{id: string, role: 'user'|'bot', text: string}> the user's own messages and the assistant's answers */
    public function transcript(): array
    {
        return array_slice($this->lines(), -self::TRANSCRIPT_LINES);
    }

    /**
     * Everything about one answer, read from the conversation itself: the answer, the user's question before it, the
     * transcript up to it, and the actions it used with their input and whether they worked. Null when this
     * conversation has no answer with that id.
     *
     * @return array{answer: string, question: ?string, transcript: list<array{role: string, text: string}>, tools: list<array<string, mixed>>}|null
     */
    public function answer(string $id, bool $withResults = false): ?array
    {
        $lines = $this->lines();
        $at = null;
        foreach ($lines as $i => $line) {
            if ($line['id'] === $id && $line['role'] === 'bot') {
                $at = $i;
            }
        }
        if ($at === null) {
            return null;
        }
        $question = null;
        for ($i = $at - 1; $i >= 0; $i--) {
            if ($lines[$i]['role'] === 'user') {
                $question = $lines[$i]['text'];
                break;
            }
        }

        // the actions of this answer: its tool calls, and the results the app sent back for them
        $results = [];
        foreach ($this->messages as $message) {
            if ($message['role'] === 'user' && is_array($message['content'])) {
                foreach ($message['content'] as $block) {
                    if (($block['type'] ?? '') === 'tool_result') {
                        $results[$block['tool_use_id'] ?? ''] = $block;
                    }
                }
            }
        }
        $tools = [];
        foreach ($this->messages as $message) {
            if ($message['role'] !== 'assistant' || ($message['id'] ?? null) !== $id) {
                continue;
            }
            foreach ((array) $message['content'] as $block) {
                if (($block['type'] ?? '') !== 'tool_use') {
                    continue;
                }
                $result = $results[$block['id'] ?? ''] ?? null;
                $content = is_string($result['content'] ?? null) ? $result['content'] : json_encode($result['content'] ?? null, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                $ok = $result !== null && empty($result['is_error']);
                $decoded = json_decode((string) $content, true);
                $tools[] = array_filter([
                    'name' => $block['name'],
                    'input' => self::input($block['input'] ?? null),
                    'ok' => $ok,
                    'error' => $result === null ? 'no result' : ($ok ? null : (is_array($decoded) ? ($decoded['error'] ?? $content) : $content)),
                    'result' => $withResults && $ok ? Str::limit((string) $content, self::RESULT_LIMIT) : null,
                ], fn ($value) => $value !== null);
            }
        }

        return [
            'answer' => $lines[$at]['text'],
            'question' => $question,
            'transcript' => array_map(fn (array $line) => ['role' => $line['role'], 'text' => $line['text']], array_slice($lines, 0, $at + 1)),
            'tools' => $tools,
        ];
    }

    /** True when the answer with this id has words the user sees. */
    public function answered(string $id): bool
    {
        foreach ($this->lines() as $line) {
            if ($line['id'] === $id && $line['role'] === 'bot') {
                return true;
            }
        }

        return false;
    }

    /** Every line the user saw, an answer in several parts joined into one, before the transcript is cut. */
    private function lines(): array
    {
        $lines = [];
        foreach ($this->messages as $message) {
            if ($message['role'] === 'user' && is_string($message['content'])) {
                $lines[] = ['id' => (string) ($message['id'] ?? ''), 'role' => 'user', 'text' => $message['content']];
            } elseif ($message['role'] === 'assistant') {
                $text = trim(implode("\n", array_map(
                    fn (array $block) => $block['text'] ?? '',
                    array_filter((array) $message['content'], fn ($block) => ($block['type'] ?? '') === 'text'),
                )));
                if ($text === '') {
                    continue;
                }
                $id = (string) ($message['id'] ?? '');
                $last = array_key_last($lines);
                if ($last !== null && $lines[$last]['role'] === 'bot' && $lines[$last]['id'] === $id && $id !== '') {
                    $lines[$last]['text'] .= "\n\n".$text;
                } else {
                    $lines[] = ['id' => $id, 'role' => 'bot', 'text' => $text];
                }
            }
        }

        return $lines;
    }

    /** A conversation stored before messages had ids gets them now: one per user message, one per answer. */
    private function numberStoredMessages(): void
    {
        $answer = null;
        foreach ($this->messages as $i => $message) {
            if ($message['role'] === 'user') {
                $isResult = is_array($message['content']) && ($message['content'][0]['type'] ?? '') === 'tool_result';
                if (! $isResult) {
                    $answer = null;
                    if (is_string($message['content'])) {
                        $this->messages[$i]['id'] = $this->nextId();
                    }
                }
            } elseif ($message['role'] === 'assistant') {
                $answer ??= $this->nextId();
                $this->messages[$i]['id'] = $answer;
            }
        }
    }

    /** A message the app writes for the user, such as the outcome of a confirmed action; the transcript leaves it out. */
    public function report(string $text): void
    {
        $this->messages[] = ['role' => 'user', 'content' => [['type' => 'text', 'text' => $text]]];
    }

    public function forget(): void
    {
        Cache::forget($this->key());
    }

    /** The id of a confirmation for exactly this action and input. */
    public static function confirmId(string $tool, array $input): string
    {
        ksort($input);

        return substr(hash('sha256', $tool.'|'.json_encode($input)), 0, 24);
    }

    /** Who the conversation belongs to, as a hash: the same for every conversation of one user or visitor. */
    public function ownerKey(): string
    {
        return sha1($this->owner);
    }

    private function key(): string
    {
        return 'rekhu-assistant:'.sha1($this->owner).':'.$this->id;
    }
}
