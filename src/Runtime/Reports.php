<?php

declare(strict_types=1);

namespace Rekhu\Assistant\Runtime;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Rekhu\Assistant\Events\AnswerReported;
use Rekhu\Assistant\Jobs\SendReport;
use Rekhu\Assistant\RekhuAssistant;
use Throwable;

/**
 * "Report a problem" on an answer. The report is built here, from the conversation the server kept, never from what
 * the browser says the answer was; it goes to the Rekhu account as the same customer, user and conversation as the
 * model calls, and AnswerReported is dispatched for the app. When Rekhu cannot be reached the user is still thanked:
 * the report is tried once more, on the queue when the app has one.
 */
final class Reports
{
    public static function enabled(bool $visitor = false): bool
    {
        return (bool) config('rekhu-assistant.reports.enabled', true)
            && (! $visitor || (bool) config('rekhu-assistant.reports.guests', true));
    }

    /** @return list<string> the reason keys a report may give */
    public static function keys(): array
    {
        return array_map('strval', array_keys((array) config('rekhu-assistant.reports.reasons', [])));
    }

    public static function maxComment(): int
    {
        return (int) config('rekhu-assistant.reports.max_comment', 1000);
    }

    /** What the history tells the client, in the user's language; null when reports are off. */
    public static function offer(bool $visitor): ?array
    {
        if (! self::enabled($visitor)) {
            return null;
        }
        $reasons = [];
        foreach ((array) config('rekhu-assistant.reports.reasons', []) as $key => $label) {
            $built = 'rekhu-assistant::messages.reason_'.$key;
            $reasons[] = ['key' => (string) $key, 'label' => RekhuAssistant::translated($label)
                ?? (trans()->has($built) ? __($built) : Str::headline((string) $key))];
        }

        return ['reasons' => $reasons, 'max_comment' => self::maxComment()];
    }

    /** True when this user or visitor may send one more report this hour; counts the attempt. */
    public static function allowed(Conversation $conversation): bool
    {
        $key = 'rekhu-assistant:reports:'.$conversation->ownerKey();
        if (RateLimiter::tooManyAttempts($key, (int) config('rekhu-assistant.reports.per_user_per_hour', 20))) {
            return false;
        }
        RateLimiter::hit($key, 3600);

        return true;
    }

    /**
     * Builds and sends the report on one answer. Returns false when the conversation has no such answer for this user.
     * A second report on the same answer replaces the first in the Rekhu account.
     */
    public static function submit(?Authenticatable $user, ?string $visitor, Conversation $conversation, string $message, string $reason, ?string $comment, ?string $locale): bool
    {
        $answer = $conversation->exists ? $conversation->answer($message, (bool) config('rekhu-assistant.reports.include_results', false)) : null;
        if (! $answer) {
            return false;
        }
        $report = [
            'conversation' => $conversation->id,
            'message' => $message,
            'reason' => $reason,
            'comment' => $comment !== null && trim($comment) !== '' ? trim($comment) : null,
            'locale' => $locale ?: app()->getLocale(),
            'question' => $answer['question'],
            'answer' => $answer['answer'],
            'transcript' => array_slice($answer['transcript'], -30),
            'tools' => $answer['tools'],
        ];

        $platform = new Platform($user, $conversation->id, $visitor);
        try {
            $platform->report($report);
        } catch (Throwable $e) {
            report($e);
            self::retry($platform->who(), $report);
        }
        AnswerReported::dispatch($user, $visitor, $conversation->id, $message, $reason, $report['comment'], $report);

        return true;
    }

    /** Once more: on the queue when the app has one, else right away. */
    private static function retry(array $who, array $report): void
    {
        if (config('queue.default', 'sync') !== 'sync') {
            SendReport::dispatch($who, $report)->delay(now()->addMinute());

            return;
        }
        try {
            Platform::sendReport($who, $report);
        } catch (Throwable $e) {
            report($e);
        }
    }
}
