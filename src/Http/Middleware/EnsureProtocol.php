<?php

declare(strict_types=1);

namespace Rekhu\Assistant\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Rekhu\Assistant\Protocol;
use Symfony\Component\HttpFoundation\Response;

/**
 * Runs before the app's own middleware on every chat route. Errors answer as JSON, also those of the app's sign-in
 * and CSRF checks: the widget asks for an event stream, and would otherwise get a redirect to the login page instead
 * of a 401 or 419 it can act on. A client that asks for another protocol version gets a clear 400.
 */
final class EnsureProtocol
{
    public function handle(Request $request, Closure $next): Response
    {
        $request->headers->set('Accept', 'application/json, text/event-stream');
        $asked = $request->header(Protocol::HEADER);
        if ($asked !== null && (int) $asked !== Protocol::VERSION) {
            Protocol::refuse(400, __('rekhu-assistant::messages.protocol', ['version' => Protocol::VERSION]));
        }

        return $next($request);
    }
}
