<?php

namespace Rekhu\Assistant;

use Closure;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * What the package knows about itself and the app: the key, and who the customer is behind a signed-in user.
 *
 * An app that serves many customers names the customer once, in a service provider:
 *
 *     RekhuAssistant::tenantUsing(fn ($user) => $user->team);              // a model: its key and name are used
 *     RekhuAssistant::tenantUsing(fn ($user) => ['id' => $user->company_id, 'name' => $user->company->name]);
 *
 * Usage, memory and limits on Rekhu are then kept per customer, so the developer can bill each one.
 */
class RekhuAssistant
{
    public const VERSION = '0.1.0';

    private static ?Closure $tenant = null;

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

    public static function helpPath(): string
    {
        return (string) (config('rekhu-assistant.help_path') ?: resource_path('rekhu/help'));
    }
}
