<?php

namespace Rekhu\Assistant;

use Closure;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Gate;
use Rekhu\Assistant\Http\AssistantRoutes;

/**
 * What the package knows about itself and the app: the key, and who the customer is behind a signed-in user.
 *
 * An app that serves many customers names the customer once, in a service provider:
 *
 *     RekhuAssistant::tenantUsing(fn ($user) => $user->team);              // a model: its key and name are used
 *     RekhuAssistant::tenantUsing(fn ($user) => ['id' => $user->company_id, 'name' => $user->company->name]);
 *
 * Usage, memory and limits on Rekhu are then kept per customer, so the developer can bill each one.
 *
 * An app that checks rights its own way (not with "can:" middleware) says which actions and pages a user may use:
 *
 *     RekhuAssistant::authorizeUsing(fn ($user, $route) => $user->isAbleTo($route->getName()));
 *
 * A front end of its own (Nuxt, Next, a mobile app) lists its pages, as the user may open them:
 *
 *     RekhuAssistant::pagesUsing(fn ($user) => [['path' => '/orders', 'label' => 'Orders'], …]);
 */
class RekhuAssistant
{
    public const VERSION = '0.9.4';

    /** The Rekhu product the keys of this package belong to: agk_<account>_assistant_<secret>. */
    public const PRODUCT = 'assistant';

    /** Why a key of another Rekhu product (Rekhu Builder's laravel-dev, for one) does not work here; null when it is ours. */
    public static function wrongProduct(string $agent): ?string
    {
        return $agent === self::PRODUCT ? null
            : "This key is for {$agent}, not for Rekhu Assistant. Make an assistant key on the Connection tab of the project in your Rekhu account (Rekhu Assistant, one key per environment).";
    }

    private static ?Closure $tenant = null;

    /** Set by ignoreRoutes(): the package registers no routes of its own. */
    public static bool $ignoresRoutes = false;

    /** The package registers no routes of its own; call routes() in your route file instead. In a service provider's register(). */
    public static function ignoreRoutes(): void
    {
        self::$ignoresRoutes = true;
    }

    /**
     * The assistant's routes, in your own route file and group: your domain, prefix, middleware and names around them.
     * Options: path (default rekhu-assistant.path), middleware, guests, guest_middleware, widget (the script route).
     *
     *     Route::prefix('api/v2')->group(fn () => RekhuAssistant::routes(['path' => 'assistant', 'middleware' => ['api', 'auth:sanctum']]));
     */
    public static function routes(array $options = []): void
    {
        AssistantRoutes::register($options);
    }

    private static ?Closure $authorize = null;

    private static ?Closure $pages = null;

    private static ?Closure $access = null;

    /** @var list<class-string> */
    private static array $tools = [];

    /** Tool classes outside app/Assistant/Tools (a module, a package), offered like the ones found there. */
    public static function tools(array $classes): void
    {
        self::$tools = array_values(array_unique([...self::$tools, ...$classes]));
    }

    /** @return list<class-string> */
    public static function registeredTools(): array
    {
        return self::$tools;
    }

    /**
     * fn ($user): bool — who may use the chat at all, beside the middleware of the chat routes. A user it refuses gets a
     * 403 with a message in their language, and the widget says so.
     *
     *     RekhuAssistant::accessUsing(fn ($user) => $user->can('use-assistant'));
     */
    public static function accessUsing(?Closure $resolver): void
    {
        self::$access = $resolver;
    }

    public static function allowsAccess(?Authenticatable $user): bool
    {
        return ! self::$access || ($user && (bool) (self::$access)($user));
    }

    /** A text from the config: as it is, or the one for the app's locale from ['en' => …, 'ar' => …], else English, else the first. */
    public static function translated(mixed $value): ?string
    {
        if (is_array($value)) {
            $locale = app()->getLocale();
            $value = $value[$locale] ?? $value[strtok($locale, '-_')] ?? $value['en'] ?? (reset($value) ?: null);
        }

        return is_string($value) && $value !== '' ? $value : null;
    }

    /** fn ($user, Route $route): bool — false hides the action or page from the user; the call itself is still checked by the route. */
    public static function authorizeUsing(?Closure $resolver): void
    {
        self::$authorize = $resolver;
    }

    public static function authorizes(Authenticatable $user, Route $route): bool
    {
        return ! self::$authorize || (bool) (self::$authorize)($user, $route);
    }

    /** fn (?$user): array — the front end's pages this user may open, each ['path', 'label', 'help'?, 'name'?]; $user is null for a visitor and for rekhu:assistant without --user. */
    public static function pagesUsing(?Closure $resolver): void
    {
        self::$pages = $resolver;
    }

    /** @return list<array<string, mixed>> the front end's pages for the user: from the config, then from pagesUsing() */
    public static function frontendPages(?Authenticatable $user): array
    {
        $listed = array_values(array_filter((array) config('rekhu-assistant.pages.frontend.list', []), function ($page) use ($user) {
            // a page with "can" is offered when the user passes that gate
            return is_array($page) && (empty($page['can']) || $user && Gate::forUser($user)->allows($page['can']));
        }));

        return [...$listed, ...array_values((array) (self::$pages ? (self::$pages)($user) : []))];
    }

    public static function tenantUsing(Closure $resolver): void
    {
        self::$tenant = $resolver;
    }

    /** The key's parts: agk_<customer>_<agent>_<secret>. Null when there is no usable key. */
    public static function key(): ?array
    {
        $key = (string) config('rekhu-assistant.key');
        if (! preg_match('/^agk_([a-z0-9-]+)_([a-z0-9-]+)_[A-Za-z0-9_-]+$/', $key, $m)) {
            return null;
        }

        return ['key' => $key, 'customer' => $m[1], 'agent' => $m[2]];
    }

    /** ['id' => …, 'name' => …] of the developer's customer the user belongs to; the app itself when none is set. */
    public static function tenant(?Authenticatable $user): array
    {
        $tenant = self::$tenant && $user ? (self::$tenant)($user) : null;
        if (is_object($tenant) && method_exists($tenant, 'getKey')) {
            $tenant = ['id' => $tenant->getKey(), 'name' => $tenant->name ?? $tenant->title ?? null];
        } elseif (is_scalar($tenant)) {
            $tenant = ['id' => $tenant];
        }
        if (! is_array($tenant) || ($tenant['id'] ?? '') === '') {
            return ['id' => parse_url((string) config('app.url'), PHP_URL_HOST) ?: 'app', 'name' => (string) config('app.name')];
        }

        return ['id' => (string) $tenant['id'], 'name' => (string) ($tenant['name'] ?? $tenant['id'])];
    }

    /** All visitors of the public chat together: one customer on Rekhu, with its own limit. */
    public static function visitors(): array
    {
        $tenant = (array) config('rekhu-assistant.guests.tenant', []);

        return ['id' => (string) ($tenant['id'] ?? 'visitors'), 'name' => (string) ($tenant['name'] ?? 'Visitors')];
    }

    /** The user as the platform knows them: the id only, never a name or e-mail. */
    public static function userRef(?Authenticatable $user): string
    {
        return $user ? (string) $user->getAuthIdentifier() : '';
    }

    /**
     * The first message of the chat, in the user's language: the config's greeting (guests.greeting for a visitor), a
     * text or one per language (['ar' => …, 'en' => …]), picked by the app's locale, else English, else the first.
     */
    public static function greeting(bool $visitor = false): ?string
    {
        return self::translated(($visitor ? config('rekhu-assistant.guests.greeting') : null) ?? config('rekhu-assistant.greeting'));
    }

    public static function helpPath(): string
    {
        return (string) (config('rekhu-assistant.help_path') ?: resource_path('rekhu/help'));
    }
}
