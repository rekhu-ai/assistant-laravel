<?php

namespace Rekhu\Assistant\Catalogue;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as Router;
use Illuminate\Support\Str;
use Rekhu\Assistant\Attributes\Tool;

/** The actions of the app: every route whose controller method carries #[Tool], as the user may use them. */
class Actions
{
    /**
     * name => ['name', 'description', 'confirm', 'route', 'schema'] for the user; "all" ignores what the user may do.
     * Without a user (a visitor in the public chat) only the actions marked guests: true, on routes without login.
     */
    public static function for(?Authenticatable $user, bool $all = false): array
    {
        $out = [];
        foreach (Router::getRoutes()->getRoutes() as $route) {
            $tool = Routes::attribute($route, Tool::class);
            if (! $tool || (! $all && (! $user && ! $tool->guests || ! Routes::allows($route, $user)))) {
                continue;
            }
            $name = self::name($route, $tool);
            $verb = Routes::verb($route);
            $out[$name] = [
                'name' => $name,
                'description' => $tool->description,
                'confirm' => $tool->confirm ?? $verb === 'DELETE',
                'verb' => $verb,
                'uri' => $route->uri(),
                'route' => $route,
                'schema' => Schema::of($route, $tool->rules, $user),
            ];
        }

        return $out;
    }

    /** Tool names the model sees: the attribute's, else the route name ("orders.reorder" → orders_reorder). */
    public static function name(Route $route, Tool $tool): string
    {
        $name = $tool->name ?: ($route->getName() ?: Str::of($route->getActionName())->afterLast('\\')->replace('@', '_')->snake());

        return Str::limit(preg_replace('/[^a-zA-Z0-9_-]+/', '_', (string) $name), 60, '');
    }
}
