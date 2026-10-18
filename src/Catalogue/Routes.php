<?php

namespace Rekhu\Assistant\Catalogue;

use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Gate;
use ReflectionMethod;
use Rekhu\Assistant\RekhuAssistant;
use Throwable;

/** What a route says about itself: the attribute on its controller method, and whether a user may use it. */
class Routes
{
    /** The attribute of $class on the route's controller method, or null (closures and invokables included). */
    public static function attribute(Route $route, string $class): ?object
    {
        $method = self::method($route);
        $found = $method?->getAttributes($class)[0] ?? null;

        return $found?->newInstance();
    }

    public static function method(Route $route): ?ReflectionMethod
    {
        $uses = $route->getAction('uses');
        if (! is_string($uses)) {
            return null;
        }
        [$class, $method] = str_contains($uses, '@') ? explode('@', $uses, 2) : [$uses, '__invoke'];
        try {
            return class_exists($class) && method_exists($class, $method) ? new ReflectionMethod($class, $method) : null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * False when the route's "can:" middleware refuses the user, or it is for guests only. A "can:" that needs a
     * record (can:update,order) cannot be checked before the record is known, so it is left to the call itself.
     * A visitor (no user) only reaches routes without login: no auth, verified, password confirmation or "can:".
     * For a user, RekhuAssistant::authorizeUsing() has the last word.
     */
    public static function allows(Route $route, ?Authenticatable $user): bool
    {
        foreach ($route->gatherMiddleware() as $middleware) {
            if (! is_string($middleware)) {
                if (! $user) {
                    return false;             // middleware we cannot read: not for visitors
                }

                continue;
            }
            $name = explode(':', $middleware, 2)[0];
            if (! $user && (in_array($name, ['auth', 'auth.basic', 'auth.session', 'verified', 'password.confirm', 'can', 'signed'], true) || class_exists($name) && is_a($name, Authenticate::class, true))) {
                return false;
            }
            if ($user && ($middleware === 'guest' || str_starts_with($middleware, 'guest:'))) {
                return false;
            }
            if (str_starts_with($middleware, 'can:')) {
                $arguments = explode(',', substr($middleware, 4));
                if (count($arguments) === 1 || ! in_array($arguments[1], $route->parameterNames(), true) && ! str_contains($arguments[1], '\\')) {
                    if (! $user || Gate::forUser($user)->denies($arguments[0])) {
                        return false;
                    }
                }
            }
        }

        // the app's own rule for what this user may use, when it checks rights without "can:"
        return ! $user || RekhuAssistant::authorizes($user, $route);
    }

    /** GET, POST, PUT, PATCH or DELETE: the route's first method that is not HEAD. */
    public static function verb(Route $route): string
    {
        return collect($route->methods())->first(fn ($m) => $m !== 'HEAD') ?? 'GET';
    }
}
