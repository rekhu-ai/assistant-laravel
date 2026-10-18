<?php

namespace Rekhu\Assistant\Runtime;

use Illuminate\Contracts\Auth\Authenticatable;
use Rekhu\Assistant\Catalogue\Actions;
use Rekhu\Assistant\RekhuAssistant;

/**
 * The developer's own quick questions for a fresh chat, as written in the config: passed on as they are, in the user's
 * language, and only those whose actions ("needs") the user may use. How they look is up to the front end.
 */
class Quick
{
    /** @return list<string> */
    public static function for(?Authenticatable $user): array
    {
        $items = (array) config('rekhu-assistant.quick', []);
        if (! $items) {
            return [];
        }
        $may = $user ? array_keys(Actions::for($user)) : array_keys(Actions::for(null));
        $out = [];
        foreach ($items as $item) {
            $item = is_array($item) && array_key_exists('text', $item) ? $item : ['text' => $item];
            if (! empty($item['needs']) && ! array_intersect((array) $item['needs'], $may)) {
                continue;
            }
            if ($text = RekhuAssistant::translated($item['text'])) {
                $out[] = $text;
            }
        }

        return $out;
    }
}
