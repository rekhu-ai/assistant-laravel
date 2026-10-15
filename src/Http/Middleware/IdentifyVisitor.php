<?php

declare(strict_types=1);

namespace Rekhu\Assistant\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Rekhu\Assistant\Protocol;
use Symfony\Component\HttpFoundation\Response;

/**
 * The public chat: a visitor names themselves with an anonymous id (X-Rekhu-Visitor). From here on the request has no
 * user, so someone who is signed in and opens the public chat is a visitor there too and never acts as themselves.
 */
final class IdentifyVisitor
{
    public const HEADER = 'X-Rekhu-Visitor';

    private const ATTRIBUTE = 'rekhu-assistant.visitor';

    public function handle(Request $request, Closure $next): Response
    {
        $id = (string) $request->header(self::HEADER);
        if (! Str::isUuid($id)) {
            Protocol::refuse(422, __('rekhu-assistant::messages.visitor_id'));
        }
        $request->attributes->set(self::ATTRIBUTE, strtolower($id));
        $request->setUserResolver(fn () => null);

        return $next($request);
    }

    /** The visitor of a public-chat request; null on the signed-in chat. */
    public static function of(Request $request): ?string
    {
        $visitor = $request->attributes->get(self::ATTRIBUTE);

        return is_string($visitor) ? $visitor : null;
    }
}
