<?php

namespace Rekhu\Assistant\Catalogue;

use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as Router;
use Illuminate\Support\Str;
use Rekhu\Assistant\Attributes\Tool;
use Throwable;

/**
 * The app as the scan reads it: its routes, never its records. Per route the verb, address, name, middleware, the inputs
 * it validates, and the code of its controller method, so Rekhu can say what it does. Routes the code already decides
 * (#[Tool], the config's actions.include or actions.exclude) are left out, and so are the framework's and tools' own.
 */
class Outline
{
    /** Routes that are never an action: the package's, sign-in, and the routes of debug and admin tools. */
    private const NEVER = ['rekhu.*', 'rekhu-assistant.*', 'sanctum.*', 'ignition.*', 'debugbar.*', 'telescope*', 'horizon.*', 'pulse*', 'livewire.*', 'filament.*', 'nova.*', 'storage.*', 'login', 'logout', 'register', 'password.*', 'verification.*'];

    private const NEVER_URIS = ['_ignition/*', '_debugbar/*', 'telescope*', 'horizon*', 'pulse*', 'livewire/*', 'sanctum/*', 'storage/*', 'up', 'rekhu*'];

    /** @return list<array{key: string, verb: string, uri: string, name: ?string, middleware: list<string>, inputs: array, code: ?string, route: Route}> */
    public static function routes(bool $withCode = true): array
    {
        $exclude = (array) config('rekhu-assistant.actions.exclude', []);
        $include = (array) config('rekhu-assistant.actions.include', []);
        $out = [];
        foreach (Router::getRoutes()->getRoutes() as $route) {
            $name = (string) $route->getName();
            $verb = Routes::verb($route);
            if (in_array($verb, ['OPTIONS'], true) || Str::is(self::NEVER, $name) || Str::is(self::NEVER_URIS, $route->uri())) {
                continue;
            }
            if ($exclude && (Str::is($exclude, $name) || Str::is($exclude, $route->uri()))) {
                continue;
            }
            if (isset($include[$name]) || isset($include[$verb.' '.$route->uri()]) || Routes::attribute($route, Tool::class)) {
                continue;
            }
            $key = ActionsFile::key($route);
            $out[$key] = [
                'key' => $key,
                'verb' => $verb,
                'uri' => $route->uri(),
                'name' => $name ?: null,
                'middleware' => array_values(array_filter(array_map(fn ($m) => is_string($m) ? $m : null, $route->gatherMiddleware()))),
                'inputs' => self::inputs($route),
                'code' => $withCode ? self::code($route) : null,
                'route' => $route,
            ];
        }

        return array_values($out);
    }

    /** What the route validates, as the assistant would be shown it. */
    private static function inputs(Route $route): array
    {
        try {
            return (array) (Schema::of($route, [], null, [])['properties'] ?? []);
        } catch (Throwable) {
            return [];
        }
    }

    /** The controller method's code with its doc comment, cut to its first lines; null for a closure or a missing class. */
    private static function code(Route $route): ?string
    {
        $method = Routes::method($route);
        if (! $method || ! $method->getFileName() || ! is_file($method->getFileName())) {
            return null;
        }
        $lines = file($method->getFileName());
        $start = max(0, $method->getStartLine() - 1);
        $length = min(80, $method->getEndLine() - $start);
        $code = implode('', array_slice($lines, $start, max(1, $length)));
        $doc = $method->getDocComment();

        return Str::limit(($doc ? $doc."\n" : '').$method->getDeclaringClass()->getName()."\n".$code, 4000, '');
    }
}
