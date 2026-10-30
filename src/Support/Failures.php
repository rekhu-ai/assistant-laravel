<?php

declare(strict_types=1);

namespace Rekhu\Assistant\Support;

use Illuminate\Support\Facades\Log;
use Rekhu\Assistant\Runtime\ChatLog;
use Rekhu\Assistant\Runtime\Refused;
use Throwable;

/**
 * A turn that failed: the developer finds the reason in the log, the user reads what it means for them and what to do:
 * try again soon, let the app's administrator know, or start a new chat. Never the technical reason itself.
 */
final class Failures
{
    public static function report(Throwable $e): string
    {
        ChatLog::failure($e);
        $key = self::kind($e);
        match ($key) {
            'paused' => Log::warning('Rekhu Assistant: the assistant of this project is paused or at its monthly limit; see the Assistant tab of the project in your Rekhu account. '.$e->getMessage()),
            'credit' => Log::error('Rekhu Assistant: the credit of your Rekhu account is used up, so the assistant cannot answer; top up under Billing in your Rekhu account. '.$e->getMessage()),
            'busy' => Log::warning('Rekhu Assistant: the model is overloaded or rate limited; the user was asked to try again. '.$e->getMessage()),
            'conversation' => Log::error('Rekhu Assistant: the model refused this conversation; the user was asked to start a new chat. '.$e->getMessage(), ['exception' => $e]),
            default => Log::error('Rekhu Assistant: '.$e->getMessage(), ['exception' => $e]),
        };

        return __('rekhu-assistant::messages.'.$key);
    }

    /** paused, credit, busy, conversation or unavailable: the message key for what went wrong. */
    public static function kind(Throwable $e): string
    {
        $type = $e instanceof Refused ? $e->type : '';
        $status = $e instanceof Refused ? $e->getCode() : 0;

        return match (true) {
            $type === 'app_limit' => 'paused',
            $type === 'billing_error' || $status === 402 => 'credit',
            in_array($type, ['rate_limit_error', 'overloaded_error'], true) || in_array($status, [429, 529], true) || str_contains(strtolower($e->getMessage()), 'overloaded') => 'busy',
            $type === 'invalid_request_error' || $status === 400 => 'conversation',
            default => 'unavailable',
        };
    }
}
