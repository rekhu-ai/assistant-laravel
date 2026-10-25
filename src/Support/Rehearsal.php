<?php

declare(strict_types=1);

namespace Rekhu\Assistant\Support;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Rekhu\Assistant\Runtime\Dispatcher;
use Rekhu\Assistant\Runtime\Shape;
use Rekhu\Assistant\Runtime\Turn;
use Throwable;

/**
 * An action run from the console exactly as the chat runs it: as the user, signed in on every guard the app has (so
 * auth:sanctum and the like pass), in their language, through the route's own middleware. Returns the outcome and
 * what the assistant would read of it.
 */
final class Rehearsal
{
    /** @return array{outcome: array, ms: int, read: string, size: int} */
    public static function run(array $action, array $input, ?Authenticatable $user, ?string $locale = null): array
    {
        if ($locale) {
            app()->setLocale($locale);
        }
        $server = $locale ? ['HTTP_ACCEPT_LANGUAGE' => $locale] : [];
        $outer = Request::create('/'.trim((string) config('rekhu-assistant.path', 'rekhu/assistant'), '/').'/chat', 'POST', [], [], [], $server);
        $outer->setUserResolver(fn () => $user);
        if ($user) {
            foreach (array_keys((array) config('auth.guards', [])) as $guard) {
                try {
                    Auth::guard($guard)->setUser($user);
                } catch (Throwable) {
                }
            }
        }
        app()->instance('request', $outer);
        $outer->setUserResolver(fn () => $user);

        $started = hrtime(true);
        $outcome = Dispatcher::run($action, $input, $outer);
        $ms = (int) round((hrtime(true) - $started) / 1e6);
        $full = Shape::json($outcome['ok'] ? $outcome['result'] : array_filter(['error' => $outcome['error'], 'errors' => $outcome['errors'] ?? null]));
        $read = Shape::json(Shape::fit($outcome['ok'] ? $outcome['result'] : json_decode($full, true), Turn::RESULT_LIMIT));

        return ['outcome' => $outcome, 'ms' => $ms, 'read' => $read, 'size' => strlen($full)];
    }

    /** The user with this id, from the user provider of the app's default guard, or null. */
    public static function user(?string $id): ?Authenticatable
    {
        if ($id === null || $id === '') {
            return null;
        }
        $guard = (string) config('auth.defaults.guard');
        $provider = Auth::createUserProvider(config("auth.guards.$guard.provider") ?? 'users');

        return $provider?->retrieveById($id);
    }
}
