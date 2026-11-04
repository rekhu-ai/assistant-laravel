<?php

namespace Rekhu\Assistant\Catalogue;

use Illuminate\Routing\Route;
use Illuminate\Support\Str;
use Rekhu\Assistant\Attributes\Tool;

/**
 * assistant/actions.php: the actions the scan proposed, as the developer decided them. One entry per route, by its name
 * or "VERB uri": on or off, the description, confirm, inputs, fields, label. Only entries that are on are offered.
 *
 * The file is the developer's: a new scan adds routes it has not seen as "new" and off, marks routes that are gone,
 * and never changes an entry that is already there. A route with #[Tool] in its controller, or in the config's
 * actions.include, is never in the file: the code has the last word.
 */
class ActionsFile
{
    /** Keys an entry may hold; anything else in the file is left alone and ignored. */
    public const FIELDS = ['on', 'description', 'name', 'confirm', 'rules', 'guests', 'label', 'inputs', 'fields', 'why_off', 'state'];

    private static ?array $loaded = null;

    private static ?string $loadedFrom = null;

    public static function path(): string
    {
        return (string) (config('rekhu-assistant.actions.file') ?: base_path('assistant/actions.php'));
    }

    public static function exists(): bool
    {
        return is_file(self::path());
    }

    /** @return array<string, array> every entry of the file, on or off; [] when there is no file */
    public static function all(): array
    {
        $path = self::path();
        if (self::$loaded !== null && self::$loadedFrom === $path) {
            return self::$loaded;
        }
        $entries = [];
        if (is_file($path)) {
            $read = (static fn () => require $path)();
            $entries = is_array($read) ? array_filter($read, fn ($e, $k) => is_string($k) && is_array($e), ARRAY_FILTER_USE_BOTH) : [];
        }
        self::$loadedFrom = $path;

        return self::$loaded = $entries;
    }

    public static function forget(): void
    {
        self::$loaded = null;
        self::$loadedFrom = null;
    }

    /** The key of a route in the file: its name, else "VERB uri". */
    public static function key(Route $route): string
    {
        return (string) ($route->getName() ?: Routes::verb($route).' '.$route->uri());
    }

    /** The route's entry as a Tool when it is on and described; null otherwise. */
    public static function tool(Route $route): ?Tool
    {
        $entry = self::all()[self::key($route)] ?? null;
        if (! is_array($entry) || ($entry['on'] ?? false) !== true || trim((string) ($entry['description'] ?? '')) === '') {
            return null;
        }

        return new Tool(
            description: (string) $entry['description'],
            name: $entry['name'] ?? null,
            confirm: $entry['confirm'] ?? null,
            rules: is_string($entry['rules'] ?? null) ? $entry['rules'] : (array) ($entry['rules'] ?? []),
            guests: (bool) ($entry['guests'] ?? false),
            label: $entry['label'] ?? null,
            inputs: (array) ($entry['inputs'] ?? []),
            fields: (array) ($entry['fields'] ?? []),
        );
    }

    /**
     * The file after a scan: what the developer wrote stays as it is; a route seen for the first time comes in off,
     * marked "new", with the proposal; an entry whose route is gone is marked "gone" (never removed: it may come back).
     *
     * @param  array<string, array>  $current  the file as it is
     * @param  array<string, array>  $proposal  Rekhu's proposal for the routes it was shown
     * @param  list<string>  $routes  every route key the app has now (that may be in the file)
     * @return array{entries: array<string, array>, added: list<string>, gone: list<string>, kept: int}
     */
    public static function merge(array $current, array $proposal, array $routes): array
    {
        $entries = $current;
        $added = [];
        foreach ($proposal as $key => $p) {
            if (array_key_exists($key, $entries) || ! is_array($p)) {
                continue;
            }
            $entry = ['on' => false, 'state' => 'new'];
            foreach (self::FIELDS as $f) {
                if ($f !== 'on' && $f !== 'state' && array_key_exists($f, $p) && $p[$f] !== null && $p[$f] !== [] && $p[$f] !== '') {
                    $entry[$f] = $p[$f];
                }
            }
            // what Rekhu would switch on is said in the entry, so the developer sees it at a glance
            if (($p['on'] ?? false) === true) {
                $entry['proposed'] = 'on';
            }
            $entries[$key] = $entry;
            $added[] = $key;
        }
        $gone = [];
        $present = array_flip($routes);
        foreach ($entries as $key => $entry) {
            if (! isset($present[$key]) && ($entry['state'] ?? null) !== 'gone') {
                $entries[$key]['state'] = 'gone';
                $gone[] = $key;
            } elseif (isset($present[$key]) && ($entry['state'] ?? null) === 'gone') {
                unset($entries[$key]['state']);
            }
        }
        ksort($entries);

        return ['entries' => $entries, 'added' => $added, 'gone' => $gone, 'kept' => count($current)];
    }

    /** The file as PHP, readable and diffable: one entry per route, the developer's comments are not kept. */
    public static function render(array $entries): string
    {
        $out = "<?php\n\n";
        $out .= "// The actions Rekhu Assistant offers in this app, one per route (its name, or \"VERB uri\").\n";
        $out .= "// Written by php artisan rekhu:assistant:scan and yours from then on: switch an action on or off, change its\n";
        $out .= "// description, inputs, fields or label. A new scan adds routes it has not seen (off, state \"new\") and marks\n";
        $out .= "// routes that are gone (state \"gone\"); it never changes an entry that is already here. Every action still runs\n";
        $out .= "// as the signed-in user, through the route's own middleware, policies and validation.\n\n";
        $out .= "return [\n";
        foreach ($entries as $key => $entry) {
            $out .= '    '.var_export($key, true)." => [\n";
            foreach ($entry as $field => $value) {
                $out .= '        '.var_export($field, true).' => '.self::value($value, 2).",\n";
            }
            $out .= "    ],\n";
        }

        return $out."];\n";
    }

    private static function value(mixed $value, int $depth): string
    {
        if (! is_array($value)) {
            return var_export($value, true);
        }
        if ($value === []) {
            return '[]';
        }
        $list = array_is_list($value);
        $inline = $list && count($value) <= 8 && ! array_filter($value, 'is_array');
        if ($inline) {
            return '['.implode(', ', array_map(fn ($v) => var_export($v, true), $value)).']';
        }
        $pad = str_repeat('    ', $depth + 1);
        $out = "[\n";
        foreach ($value as $k => $v) {
            $out .= $pad.($list ? '' : var_export($k, true).' => ').self::value($v, $depth + 1).",\n";
        }

        return $out.str_repeat('    ', $depth).']';
    }

    public static function write(array $entries): void
    {
        $path = self::path();
        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0755, true);
        }
        file_put_contents($path, self::render($entries));
        self::forget();
    }

    /** A short, readable name for a key in messages: "orders.index", "POST api/orders". */
    public static function label(string $key): string
    {
        return Str::limit($key, 80);
    }
}
