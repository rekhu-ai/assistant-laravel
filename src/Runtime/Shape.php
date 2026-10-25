<?php

namespace Rekhu\Assistant\Runtime;

/**
 * What an action returned, made fit for the model: only the fields the action asks for, without a paginator's links,
 * and short enough to read whole. A list that is too long loses its last rows, never its closing brackets or its
 * paging, and the model is told how many it got.
 */
class Shape
{
    /** Keeps $fields (dot paths, "*" for each item of a list) of every record: the records of "data" or of the list itself. */
    public static function pick(mixed $result, array $fields): mixed
    {
        if (! $fields || ! is_array($result)) {
            return $result;
        }
        if (array_is_list($result)) {
            return array_map(fn ($row) => self::only($row, $fields), $result);
        }
        if (isset($result['data']) && is_array($result['data'])) {
            $result['data'] = array_is_list($result['data'])
                ? array_map(fn ($row) => self::only($row, $fields), $result['data'])
                : self::only($result['data'], $fields);

            return $result;
        }

        return self::only($result, $fields);
    }

    /** Drops what only a web page needs (a paginator's links) and shortens the result to $limit characters of JSON. */
    public static function fit(mixed $result, int $limit): mixed
    {
        if (is_array($result)) {
            unset($result['links']);
            if (isset($result['meta']) && is_array($result['meta'])) {
                unset($result['meta']['links']);
            }
            // a Laravel paginator as it is: its page links and paths
            foreach (['first_page_url', 'last_page_url', 'next_page_url', 'prev_page_url', 'path', 'links'] as $key) {
                if (array_key_exists('current_page', $result)) {
                    unset($result[$key]);
                }
            }
        }
        if (self::size($result) <= $limit) {
            return $result;
        }
        [$path, $rows] = self::longestList($result);
        if ($rows === null) {
            return ['cut' => true, 'note' => 'The result was too long to read; ask for less, with a filter or fewer per page.', 'start' => mb_substr(self::json($result), 0, $limit - 200)];
        }
        $total = count($rows);
        // the most rows that fit, found by halving
        $low = 0;
        $high = $total;
        while ($low < $high) {
            $mid = intdiv($low + $high + 1, 2);
            if (self::size(self::withRows($result, $path, array_slice($rows, 0, $mid), $total)) <= $limit) {
                $low = $mid;
            } else {
                $high = $mid - 1;
            }
        }

        return self::withRows($result, $path, array_slice($rows, 0, $low), $total);
    }

    private static function withRows(mixed $result, ?string $path, array $rows, int $total): mixed
    {
        $note = 'Only the first '.count($rows).' of the '.$total.' rows here fit; ask for fewer per page, a narrower filter or the next rows before you count or sum.';
        if ($path === null) {
            return ['rows' => $rows, 'note' => $note];
        }
        data_set($result, $path, $rows);
        $result['note'] = $note;

        return $result;
    }

    /** [dot path or null for the result itself, rows] of the longest list in the result, or [null, null] for none. */
    private static function longestList(mixed $result): array
    {
        if (is_array($result) && array_is_list($result)) {
            return [null, $result];
        }
        $best = [null, null];
        $size = 0;
        foreach (is_array($result) ? $result : [] as $key => $value) {
            if (is_array($value) && array_is_list($value) && count($value) > 1 && ($s = self::size($value)) > $size) {
                [$best, $size] = [[(string) $key, $value], $s];
            } elseif (is_array($value) && ! array_is_list($value)) {
                foreach ($value as $inner => $list) {
                    if (is_array($list) && array_is_list($list) && count($list) > 1 && ($s = self::size($list)) > $size) {
                        [$best, $size] = [[$key.'.'.$inner, $list], $s];
                    }
                }
            }
        }

        return $best;
    }

    private static function only(mixed $row, array $fields): mixed
    {
        if (! is_array($row)) {
            return $row;
        }
        $out = [];
        foreach ($fields as $field) {
            self::copy($row, explode('.', (string) $field), $out);
        }

        return $out;
    }

    /** Copies the value at $path of $from into $to, at the same place; "*" walks every item of a list. */
    private static function copy(array $from, array $path, array &$to): void
    {
        $key = array_shift($path);
        if ($key === '*') {
            foreach ($from as $i => $item) {
                if (! $path) {
                    $to[$i] = $item;
                } elseif (is_array($item)) {
                    $to[$i] ??= [];
                    self::copy($item, $path, $to[$i]);
                }
            }

            return;
        }
        if (! array_key_exists($key, $from)) {
            return;
        }
        if (! $path) {
            $to[$key] = $from[$key];
        } elseif (is_array($from[$key])) {
            $to[$key] ??= [];
            self::copy($from[$key], $path, $to[$key]);
        }
    }

    private static function size(mixed $value): int
    {
        return strlen(self::json($value));
    }

    public static function json(mixed $value): string
    {
        return (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
    }
}
