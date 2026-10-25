<?php

declare(strict_types=1);

namespace Rekhu\Assistant\Support;

use Illuminate\Support\Facades\Log;
use Rekhu\Assistant\Runtime\ChatLog;
use Rekhu\Assistant\Runtime\Refused;
use Throwable;

/**
 * A turn that failed: the developer finds the reason in the log; the user reads only that the assistant is not
 * available, since what went wrong (a limit, the credit, the network) is the developer's to fix.
 */
final class Failures
{
    public static function report(Throwable $e): string
    {
        ChatLog::failure($e);
        $type = $e instanceof Refused ? $e->type : null;
        match (true) {
            $type === 'app_limit' => Log::warning('Rekhu Assistant: the assistant of this project is paused or at its monthly limit; see the Assistant tab of the project in your Rekhu account. '.$e->getMessage()),
            $type === 'billing_error' || ($e instanceof Refused && $e->getCode() === 402) => Log::error('Rekhu Assistant: the credit of your Rekhu account is used up, so the assistant cannot answer; top up under Billing in your Rekhu account. '.$e->getMessage()),
            default => Log::error('Rekhu Assistant: '.$e->getMessage(), ['exception' => $e]),
        };

        return __('rekhu-assistant::messages.unavailable');
    }
}
