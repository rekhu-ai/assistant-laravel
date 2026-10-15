<?php

namespace Rekhu\Assistant\Catalogue;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Route as Router;
use Illuminate\Support\Str;
use Rekhu\Assistant\Attributes\Page;

/**
 * The pages the assistant may open for the user: the app's named GET routes, minus the excluded patterns, the API,
 * pages for guests and pages the user may not open. #[Page] gives one a label and a description.
 */
class Pages
{
    /** name => ['name', 'label', 'help', 'uri', 'parameters']; a visitor (no user) also gets the sign-in and register pages. */
    public static function for(?Authenticatable $user): array
    {
        $exclude = (array) config('rekhu-assistant.pages.exclude', []);
        if (! $user) {
            $exclude = array_values(array_diff($exclude, ['login', 'register']));
        }
        $out = [];
        foreach (Router::getRoutes()->getRoutes() as $route) {
            $name = $route->getName();
            if (! $name || ! in_array('GET', $route->methods(), true) || Str::is($exclude, $name) || str_starts_with($route->uri(), 'api/')) {
                continue;
            }
            $page = Routes::attribute($route, Page::class);
            if ($page?->hidden || ! Routes::allows($route, $user)) {
                continue;
            }
            $out[$name] = [
                'name' => $name,
                'label' => $page?->label ?: Str::of($name)->replace(['.', '-', '_'], ' ')->title()->toString(),
                'help' => $page?->help ?? '',
                'uri' => '/'.ltrim($route->uri(), '/'),
                'parameters' => $route->parameterNames(),
            ];
            if (count($out) >= (int) config('rekhu-assistant.pages.limit', 300)) {
                break;
            }
        }

        return $out;
    }

    /** The page list as the assistant reads it: one line per page. */
    public static function prompt(array $pages): string
    {
        return collect($pages)->map(fn ($p) => "- {$p['name']}: {$p['uri']} — {$p['label']}".($p['help'] ? ". {$p['help']}" : ''))->implode("\n");
    }
}
