<?php

declare(strict_types=1);

namespace Rekhu\Assistant\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Rekhu\Assistant\Protocol;
use Symfony\Component\HttpFoundation\Response;

/**
 * The caps of the public chat (config rekhu-assistant.guests): messages per visitor per day, per IP address per hour,
 * and from all visitors together per day. A cap of 0 is off. Runs after IdentifyVisitor.
 */
final class LimitVisitors
{
    public function handle(Request $request, Closure $next): Response
    {
        $visitor = IdentifyVisitor::of($request) ?? '';
        $guests = (array) config('rekhu-assistant.guests', []);
        $caps = [
            ['rekhu-assistant:visitor:'.$visitor, (int) ($guests['per_visitor_per_day'] ?? 20), 86400, 'limit_visitor'],
            ['rekhu-assistant:ip:'.sha1((string) $request->ip()), (int) ($guests['per_ip_per_hour'] ?? 60), 3600, 'limit_ip'],
            ['rekhu-assistant:visitors:'.now()->toDateString(), (int) ($guests['total_per_day'] ?? 1000), 86400, 'limit_total'],
        ];
        foreach ($caps as [$key, $max, , $message]) {
            if ($max > 0 && RateLimiter::tooManyAttempts($key, $max)) {
                Protocol::refuse(429, __('rekhu-assistant::messages.'.$message));
            }
        }
        foreach ($caps as [$key, , $decay]) {
            RateLimiter::hit($key, $decay);
        }

        return $next($request);
    }
}
