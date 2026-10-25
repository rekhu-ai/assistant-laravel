<?php

namespace Rekhu\Assistant\Runtime;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The chat's own log, beside the app's: storage/logs/rekhu-assistant-YYYY-MM-DD.log by default. One line for every
 * question, every action the assistant ran (its input, the outcome, how long it took) and every failure, with the
 * conversation and the user's id, so a developer sees why an answer went wrong. What an action returned is left out
 * unless log.results is on, since it can hold personal details.
 */
class ChatLog
{
    private static ?LoggerInterface $logger = null;

    public static function question(Conversation $conversation, string $text, ?string $user, ?string $visitor): void
    {
        self::write('info', 'question', ['text' => Str::limit($text, 500)], $conversation, $user, $visitor);
    }

    public static function action(Conversation $conversation, string $name, array $input, array $outcome, int $ms, ?string $user, ?string $visitor): void
    {
        $context = ['action' => $name, 'input' => $input, 'ok' => $outcome['ok'], 'ms' => $ms];
        if (! $outcome['ok']) {
            $context['error'] = $outcome['error'];
            if (! empty($outcome['errors'])) {
                $context['errors'] = $outcome['errors'];
            }
        } elseif (config('rekhu-assistant.log.results', false)) {
            $context['result'] = Str::limit(Shape::json($outcome['result']), 4000);
        } else {
            $context['size'] = strlen(Shape::json($outcome['result']));
        }
        self::write($outcome['ok'] ? 'info' : 'warning', $outcome['ok'] ? 'action' : 'action failed', $context, $conversation, $user, $visitor);
    }

    public static function answer(Conversation $conversation, int $steps, int $chars, ?string $stop, ?string $user, ?string $visitor): void
    {
        self::write($stop === 'max_tokens' ? 'warning' : 'info', 'answer', ['steps' => $steps, 'chars' => $chars, 'stop' => $stop], $conversation, $user, $visitor);
    }

    public static function failure(Throwable $e, ?Conversation $conversation = null): void
    {
        self::write('error', 'failed', ['error' => $e->getMessage(), 'exception' => class_basename($e)], $conversation, null, null);
    }

    public static function enabled(): bool
    {
        return (bool) config('rekhu-assistant.log.enabled', true);
    }

    private static function write(string $level, string $event, array $context, ?Conversation $conversation, ?string $user, ?string $visitor): void
    {
        if (! self::enabled()) {
            return;
        }
        try {
            self::logger()->{$level}($event, array_filter([
                'conversation' => $conversation?->id,
                'user' => $user,
                'visitor' => $visitor,
            ], fn ($v) => $v !== null && $v !== '') + $context);
        } catch (Throwable) {
        }
    }

    private static function logger(): LoggerInterface
    {
        if ($channel = config('rekhu-assistant.log.channel')) {
            return Log::channel($channel);
        }

        return self::$logger ??= Log::build([
            'driver' => 'daily',
            'path' => (string) (config('rekhu-assistant.log.path') ?: storage_path('logs/rekhu-assistant.log')),
            'days' => (int) config('rekhu-assistant.log.days', 14),
            'level' => 'debug',
        ]);
    }

    /** For tests: the next line builds the logger again from the config. */
    public static function reset(): void
    {
        self::$logger = null;
    }
}
