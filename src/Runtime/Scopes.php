<?php

namespace Rekhu\Assistant\Runtime;

use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Chats of their own inside one app (config scopes): a marketplace chat next to the main one, a support chat, a chat
 * for one module. Each scope has its own instructions and only the actions its patterns name; the chat request says
 * which scope (scope=marketplace). Without one, the main chat with every action.
 *
 *     'scopes' => [
 *         'marketplace' => ['instructions' => 'You help resellers compare suppliers…', 'actions' => ['market.*', 'products']],
 *     ],
 */
class Scopes
{
    /** The scope the request asks for, when the config has it; null for the main chat. */
    public static function from(Request $request): ?string
    {
        $name = (string) $request->input('scope', '');

        return $name !== '' && is_array(config("rekhu-assistant.scopes.$name")) ? $name : null;
    }

    public static function config(?string $scope): array
    {
        return $scope ? (array) config("rekhu-assistant.scopes.$scope", []) : [];
    }

    /** The actions of the scope: those whose name, route name or address matches one of its patterns; all for the main chat. */
    public static function filter(array $actions, ?string $scope): array
    {
        if (! $scope) {
            return $actions;
        }
        $patterns = (array) (self::config($scope)['actions'] ?? []);
        if (! $patterns) {
            return [];
        }

        return array_filter($actions, function (array $action) use ($patterns) {
            $names = array_filter([$action['name'], isset($action['route']) ? (string) $action['route']->getName() : null, $action['uri'] ?? null]);
            foreach ($names as $name) {
                if (Str::is($patterns, $name)) {
                    return true;
                }
            }

            return false;
        });
    }

    /** The scope's own instructions, or null. */
    public static function instructions(?string $scope): ?string
    {
        $text = trim((string) (self::config($scope)['instructions'] ?? ''));

        return $text !== '' ? $text : null;
    }
}
