<?php

namespace Rekhu\Assistant\Catalogue;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as Router;
use Illuminate\Support\Str;
use Rekhu\Assistant\Attributes\Tool;
use Rekhu\Assistant\RekhuAssistant;

/**
 * The actions of the app, as the user may use them: every route whose controller method carries #[Tool], and the routes
 * the config adds (actions.include, by route name or "VERB uri"), minus the routes the config blocks (actions.exclude,
 * name or uri patterns), whatever their attribute says.
 */
class Actions
{
    /**
     * name => ['name', 'description', 'confirm', 'route', 'schema'] for the user; "all" ignores what the user may do.
     * Without a user (a visitor in the public chat) only the actions marked guests: true, on routes without login.
     */
    public static function for(?Authenticatable $user, bool $all = false): array
    {
        $out = [];
        $exclude = (array) config('rekhu-assistant.actions.exclude', []);
        foreach (Router::getRoutes()->getRoutes() as $route) {
            if ($exclude && (Str::is($exclude, (string) $route->getName()) || Str::is($exclude, $route->uri()))) {
                continue;
            }
            [$tool, $source] = self::tool($route);
            if (! $tool || (! $all && (! $user && ! $tool->guests || ! Routes::allows($route, $user)))) {
                continue;
            }
            $name = self::name($route, $tool);
            $verb = Routes::verb($route);
            $out[$name] = [
                'name' => $name,
                'description' => $tool->description,
                'label' => RekhuAssistant::translated($tool->label),
                'confirm' => $tool->confirm ?? $verb === 'DELETE',
                'verb' => $verb,
                'uri' => $route->uri(),
                'route' => $route,
                'schema' => Schema::of($route, $tool->rules, $user),
                'source' => $source,
            ];
        }

        return $out;
    }

    /** The route's tool and where it comes from: the config (which wins) or the #[Tool] attribute; [null, null] for neither. */
    private static function tool(Route $route): array
    {
        $include = (array) config('rekhu-assistant.actions.include', []);
        $entry = $include[(string) $route->getName()] ?? $include[Routes::verb($route).' '.$route->uri()] ?? null;
        if ($entry !== null) {
            $entry = is_string($entry) ? ['description' => $entry] : (array) $entry;
            if (! empty($entry['description'])) {
                return [new Tool(
                    description: (string) $entry['description'],
                    name: $entry['name'] ?? null,
                    confirm: $entry['confirm'] ?? null,
                    rules: (array) ($entry['rules'] ?? []),
                    guests: (bool) ($entry['guests'] ?? false),
                    label: $entry['label'] ?? null,
                ), 'config'];
            }
        }
        $tool = Routes::attribute($route, Tool::class);

        return [$tool, $tool ? 'attribute' : null];
    }

    /** Tool names the model sees: the attribute's, else the route name ("orders.reorder" → orders_reorder). */
    public static function name(Route $route, Tool $tool): string
    {
        $name = $tool->name ?: ($route->getName() ?: Str::of($route->getActionName())->afterLast('\\')->replace('@', '_')->snake());

        return Str::limit(preg_replace('/[^a-zA-Z0-9_-]+/', '_', (string) $name), 60, '');
    }
}
