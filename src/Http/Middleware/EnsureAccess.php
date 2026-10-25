<?php

namespace Rekhu\Assistant\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Rekhu\Assistant\Protocol;
use Rekhu\Assistant\RekhuAssistant;

/** The app's own rule for who may use the chat (RekhuAssistant::accessUsing()), after its middleware signed the user in. */
class EnsureAccess
{
    public function handle(Request $request, Closure $next)
    {
        if (! RekhuAssistant::allowsAccess($request->user())) {
            Protocol::refuse(403, __('rekhu-assistant::messages.no_access'));
        }

        return $next($request);
    }
}
