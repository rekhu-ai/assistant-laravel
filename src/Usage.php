<?php

declare(strict_types=1);

namespace Rekhu\Assistant;

/**
 * What an action used of its own, apart from the model's tokens: a preflight check, an image improved, an SMS sent.
 * It comes out in AnswerCompleted ($event->actionUsage) with the action's name, so the app bills it with the answer.
 * A route's result carries it as Usage::attach($result, 'preflight', 1); a Tool class calls $this->usage('preflight', 1).
 */
final class Usage
{
    public const KEY = '_usage';

    /** One usage of an action: what kind, how many units, and anything the app wants to keep with it. */
    public static function make(string $kind, float|int $units = 1, array $meta = []): array
    {
        return ['kind' => $kind, 'units' => $units] + ($meta ? ['meta' => $meta] : []);
    }

    /** The result with this usage attached; the assistant never reads it. */
    public static function attach(array $result, string $kind, float|int $units = 1, array $meta = []): array
    {
        $result[self::KEY][] = self::make($kind, $units, $meta);

        return $result;
    }
}
