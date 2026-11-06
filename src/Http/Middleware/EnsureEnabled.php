<?php

namespace Rekhu\Assistant\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Rekhu\Assistant\Protocol;
use Rekhu\Assistant\RekhuAssistant;
use Rekhu\Assistant\Runtime\Scopes;

/**
 * Whether the chat is there for this user, tenant and chat of its own (RekhuAssistant::enabledUsing()): without it,
 * "not available" with available: false, which the widget takes as: show nothing.
 */
class EnsureEnabled
{
    public function handle(Request $request, Closure $next)
    {
        $visitor = IdentifyVisitor::of($request) !== null;
        if (! RekhuAssistant::enabled($visitor ? null : $request->user(), $visitor, Scopes::from($request))) {
            Protocol::refuse(403, __('rekhu-assistant::messages.not_available'), ['available' => false]);
        }

        return $next($request);
    }
}
