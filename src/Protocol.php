<?php

declare(strict_types=1);

namespace Rekhu\Assistant;

use Illuminate\Http\Exceptions\HttpResponseException;

/** Protocol 1 between the widget and this package (PROTOCOL.md). */
final class Protocol
{
    public const VERSION = 1;

    public const HEADER = 'X-Rekhu-Protocol';

    /**
     * Ends the request with a JSON error, whatever the client accepts: the widget asks for an event stream, and it
     * reads the message from the JSON body to show it.
     */
    public static function refuse(int $status, string $message, array $extra = []): never
    {
        throw new HttpResponseException(response()->json(['message' => $message] + $extra, $status));
    }
}
