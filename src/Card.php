<?php

declare(strict_types=1);

namespace Rekhu\Assistant;

use InvalidArgumentException;

/**
 * A card of the app's own, under the assistant's answer: a price with its delivery options, a wizard step, a choice of
 * products, an upload form. An action returns it with its result; the chat sends it to the front end as
 * {type: card, kind, data} and the assistant only hears that it is shown. A front end of your own (API mode) draws it
 * by its kind; the package's widget draws it with a renderer you register (see the README), else as a simple box with
 * its title, text, rows and buttons.
 *
 *     return Card::attach(['total' => 412.50], Card::make('price', ['title' => '1000 flyers A5', 'rows' => [...]]));
 *
 * A button can send a message for the user: 'buttons' => [['label' => 'Choose for me', 'message' => 'Choose the cheapest delivery']].
 */
final class Card
{
    /** Where cards travel in an action's result. */
    public const KEY = '_cards';

    /** The kinds the package draws itself; an app's own card has another kind. */
    public const RESERVED = ['confirm', 'page', 'file', 'done', 'failed'];

    /** @return array{kind: string, data: array, title?: string} */
    public static function make(string $kind, array $data = [], ?string $title = null): array
    {
        if (! preg_match('/^[a-z][a-z0-9_-]{0,39}$/', $kind) || in_array($kind, self::RESERVED, true)) {
            throw new InvalidArgumentException("A card's kind is a short lower-case name of your own, not one of ".implode(', ', self::RESERVED).": \"$kind\".");
        }

        return array_filter(['kind' => $kind, 'data' => $data, 'title' => $title], fn ($v) => $v !== null);
    }

    /** The action's result with these cards added. */
    public static function attach(array $result, array ...$cards): array
    {
        $result[self::KEY] = [...(array) ($result[self::KEY] ?? []), ...$cards];

        return $result;
    }
}
