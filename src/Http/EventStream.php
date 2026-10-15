<?php

declare(strict_types=1);

namespace Rekhu\Assistant\Http;

use Closure;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Server-sent events: each event goes out at once, as `event: {type}` with the event as JSON data. */
final class EventStream
{
    /** @param  Closure(self): void  $writer */
    public static function respond(Closure $writer): StreamedResponse
    {
        return response()->stream(fn () => $writer(new self), 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache',
            'X-Accel-Buffering' => 'no',
        ]);
    }

    /** @param  array{type: string}  $event */
    public function send(array $event): void
    {
        echo 'event: '.$event['type']."\n";
        echo 'data: '.json_encode($event, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n\n";
        if (ob_get_level() > 0) {
            ob_flush();
        }
        flush();
    }

    /** The stream as a callable, for code that only emits events. */
    public function sender(): Closure
    {
        return fn (array $event) => $this->send($event);
    }
}
