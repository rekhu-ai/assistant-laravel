<?php

namespace Rekhu\Assistant\Catalogue;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Route as Router;
use Illuminate\Support\Str;
use Rekhu\Assistant\Attributes\Page;
use Rekhu\Assistant\RekhuAssistant;

/**
 * The pages the assistant may open for the user: the app's named GET routes, minus the excluded patterns, the API,
 * pages for guests and pages the user may not open. #[Page] gives one a label and a description. An app with a front
 * end of its own (Nuxt, Next, a mobile app) adds that front end's pages: from the config (pages.frontend.list) and from
 * RekhuAssistant::pagesUsing(); they open at pages.frontend.url.
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
        // an API with a front end of its own has no pages in Laravel to send its users to: only the front end's count
        $routes = (bool) config('rekhu-assistant.pages.routes', true) ? Router::getRoutes()->getRoutes() : [];
        foreach ($routes as $route) {
            $name = $route->getName();
            // the assistant's own routes are never pages, whatever group or name prefix the app put them in
            if (! $name || ! in_array('GET', $route->methods(), true) || Str::is($exclude, $name) || str_starts_with($route->uri(), 'api/') || str_contains($name, 'rekhu.assistant.')) {
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
        foreach (RekhuAssistant::frontendPages($user) as $page) {
            $path = '/'.ltrim((string) ($page['path'] ?? ''), '/');
            if ($path === '/' && empty($page['path']) || count($out) >= (int) config('rekhu-assistant.pages.limit', 300)) {
                continue;
            }
            $name = (string) ($page['name'] ?? '') ?: (trim(Str::of($path)->replaceMatches('/\{[^}]+\}/', '')->replace('/', '.')->replaceMatches('/\.+/', '.')->trim('.')->toString()) ?: 'home');
            preg_match_all('/\{(\w+)\??\}/', $path, $m);
            $out[$name] = [
                'name' => $name,
                'label' => (string) ($page['label'] ?? '') ?: Str::of($name)->replace(['.', '-', '_'], ' ')->title()->toString(),
                'help' => (string) ($page['help'] ?? ''),
                'uri' => $path,
                'parameters' => $m[1],
                'frontend' => true,
            ];
        }

        return $out;
    }

    /** The page list as the assistant reads it: one line per page. */
    public static function prompt(array $pages): string
    {
        return collect($pages)->map(fn ($p) => "- {$p['name']}: {$p['uri']} — {$p['label']}".($p['help'] ? ". {$p['help']}" : ''))->implode("\n");
    }
}
