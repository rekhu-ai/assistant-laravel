<?php

namespace Rekhu\Assistant;

use Closure;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Gate;
use Rekhu\Assistant\Contracts\ConversationStore;
use Rekhu\Assistant\Http\AssistantRoutes;
use Rekhu\Assistant\Runtime\CacheConversations;

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
    public const VERSION = '0.13.1';

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

    public static function tenantUsing(?Closure $resolver): void
    {
        self::$tenant = $resolver;
    }

    /** Whether the app names a tenant per user (tenantUsing): then a user is known by tenant and id together. */
    public static function hasTenants(): bool
    {
        return self::$tenant !== null;
    }

    private static ?Closure $restoreTenant = null;

    /**
     * fn (array $tenant): void — puts the app back in a tenant before work that runs later (a queued report, a job of the
     * app's own): with a database per tenant, the connection switched to that tenant's. Gets ['id' => …, 'name' => …].
     */
    public static function restoreTenantUsing(?Closure $resolver): void
    {
        self::$restoreTenant = $resolver;
    }

    /** Called by the package before each piece of later work, with the tenant it kept; nothing without restoreTenantUsing. */
    public static function restoreTenant(?array $tenant): void
    {
        if (self::$restoreTenant && $tenant && ($tenant['id'] ?? '') !== '') {
            (self::$restoreTenant)(['id' => (string) $tenant['id'], 'name' => (string) ($tenant['name'] ?? $tenant['id'])]);
        }
    }

    private static ?Closure $enabled = null;

    /**
     * fn (?$user, array $tenant, ?string $scope): bool — whether the chat is there for this user, tenant and chat of its own,
     * to roll it out tenant by tenant. With false the chat answers "not available" (403, available: false) and the
     * widget shows nothing.
     */
    public static function enabledUsing(?Closure $resolver): void
    {
        self::$enabled = $resolver;
    }

    public static function enabled(?Authenticatable $user, bool $visitor, ?string $scope): bool
    {
        return ! self::$enabled || (bool) (self::$enabled)($user, $visitor ? self::visitors() : self::tenant($user), $scope);
    }

    private static ?Closure $brand = null;

    /**
     * fn (array $tenant): array — the chat's look per tenant, for apps whose customers resell it: ['name' => …, 'logo' =>
     * an https or data: image, 'colors' => ['accent' => '#…']]. With brand.rekhu off, nothing in the chat names Rekhu.
     */
    public static function brandUsing(?Closure $resolver): void
    {
        self::$brand = $resolver;
    }

    /** The brand for a tenant: config brand, then the app's resolver; only safe colours and image addresses pass. */
    public static function brand(?array $tenant): array
    {
        $brand = [
            'name' => (string) config('rekhu-assistant.name', 'Assistant'),
            'logo' => config('rekhu-assistant.brand.logo'),
            'colors' => (array) config('rekhu-assistant.brand.colors', []),
            'rekhu' => (bool) config('rekhu-assistant.brand.rekhu', true),
        ];
        if (self::$brand && $tenant) {
            $brand = array_replace($brand, array_intersect_key((array) (self::$brand)($tenant), $brand));
        }
        $brand['colors'] = array_filter(array_intersect_key((array) $brand['colors'], array_flip(['accent', 'text', 'background', 'surface', 'border'])), fn ($c) => is_string($c) && preg_match('/^#[0-9a-fA-F]{3,8}$/', $c));
        $logo = is_string($brand['logo']) ? $brand['logo'] : '';
        $brand['logo'] = preg_match('#^(https://|/[^/])#', $logo) || preg_match('#^data:image/(png|jpeg|webp|svg\+xml);base64,[A-Za-z0-9+/=]+$#', $logo) ? $logo : null;
        $brand['name'] = trim((string) $brand['name']) ?: 'Assistant';

        return $brand;
    }

    /** Whether the app set a brand of its own (brandUsing, a logo or colours): then the chat gets it with its history. */
    public static function branded(): bool
    {
        return self::$brand !== null || config('rekhu-assistant.brand.logo') || config('rekhu-assistant.brand.colors') || ! config('rekhu-assistant.brand.rekhu', true);
    }

    private static ?Closure $model = null;

    /**
     * fn (?$user, Request $request, ?string $scope): ?string — the model for this answer, by its name in your Rekhu
     * account (from the user's plan, say); null falls back to the config's model. Rekhu still refuses a model your
     * project does not allow.
     *
     *     RekhuAssistant::modelUsing(fn ($user) => $user?->team?->plan?->assistant_model);
     */
    public static function modelUsing(?Closure $resolver): void
    {
        self::$model = $resolver;
    }

    public static function model(?Authenticatable $user, Request $request, ?string $scope = null): ?string
    {
        $chosen = self::$model ? (self::$model)($user, $request, $scope) : null;
        $chosen = is_string($chosen) && trim($chosen) !== '' ? trim($chosen) : null;

        return $chosen ?? (string) (config("rekhu-assistant.scopes.$scope.model") ?? '') ?: (config('rekhu-assistant.model') ?: null);
    }

    private static mixed $store = null;

    /**
     * Where conversations are kept: a class (or instance) of Rekhu\Assistant\Contracts\ConversationStore. The default
     * keeps them in the app's cache for conversation_minutes; your own store keeps them in your own tables, with a list
     * of a user's conversations for your chat panel.
     *
     *     RekhuAssistant::conversationsUsing(App\Assistant\DatabaseConversations::class);
     */
    public static function conversationsUsing(mixed $store): void
    {
        self::$store = $store;
    }

    public static function store(): ConversationStore
    {
        $store = self::$store ?? CacheConversations::class;
        $store = is_string($store) ? app($store) : $store;
        if (! $store instanceof ConversationStore) {
            throw new \InvalidArgumentException('RekhuAssistant::conversationsUsing() needs a '.ConversationStore::class);
        }

        return $store;
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
