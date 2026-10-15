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
 */
final class Conversation
{
    /** The most lines the transcript returns. */
    private const TRANSCRIPT_LINES = 30;

    public array $messages = [];

    /** confirm id => ['tool', 'input'] shown to the user and not yet clicked */
    public array $pending = [];

    private function __construct(public string $id, private string $owner) {}

    /** The conversation $id of this user, or of this visitor in the public chat (an anonymous id from the widget). */
    public static function open(?Authenticatable $user, ?string $id, ?string $visitor = null): self
    {
        $owner = $user ? get_class($user).':'.$user->getAuthIdentifier() : ($visitor ? 'visitor:'.$visitor : 'guest');
        $id = $id && preg_match('/^[a-zA-Z0-9-]{8,64}$/', $id) ? $id : (string) Str::uuid();
        $conversation = new self($id, $owner);
        $stored = Cache::get($conversation->key());
        if (is_array($stored)) {
            $conversation->messages = $stored['messages'] ?? [];
            $conversation->pending = $stored['pending'] ?? [];
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
        Cache::put($this->key(), ['messages' => $this->messages, 'pending' => $this->pending], now()->addMinutes((int) config('rekhu-assistant.conversation_minutes', 1440)));
    }

    /** @return list<array{role: 'user'|'bot', text: string}> the user's own messages and the assistant's answers */
    public function transcript(): array
    {
        $lines = [];
        foreach ($this->messages as $message) {
            if ($message['role'] === 'user' && is_string($message['content'])) {
                $lines[] = ['role' => 'user', 'text' => $message['content']];
            } elseif ($message['role'] === 'assistant') {
                $text = trim(implode("\n", array_map(
                    fn (array $block) => $block['text'] ?? '',
                    array_filter((array) $message['content'], fn ($block) => ($block['type'] ?? '') === 'text'),
                )));
                if ($text !== '') {
                    $lines[] = ['role' => 'bot', 'text' => $text];
                }
            }
        }

        return array_slice($lines, -self::TRANSCRIPT_LINES);
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

    private function key(): string
    {
        return 'rekhu-assistant:'.sha1($this->owner).':'.$this->id;
    }
}
