<?php

declare(strict_types=1);

namespace Rekhu\Assistant\Runtime;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Rekhu\Assistant\Contracts\ConversationHistory;
use Rekhu\Assistant\Contracts\ConversationStore;

/**
 * Conversations in the app's cache, shared by all its servers, for conversation_minutes (24 hours by default): the
 * store of an app that has not run the package's migration. Kept as JSON: only strings and arrays, whatever classes the
 * app's cache store may unserialize. Each owner also has an index of their newest conversations (title, name, questions),
 * so the chat can list, page, search and rename them.
 */
final class CacheConversations implements ConversationHistory, ConversationStore
{
    private const INDEX = 100;

    public function get(string $owner, string $id): ?array
    {
        $stored = Cache::get($this->key($owner, $id));
        if (is_string($stored)) {
            $stored = json_decode($stored, true);
        }

        return is_array($stored) ? $stored : null;
    }

    public function put(string $owner, string $id, array $data, int $minutes, array $about): void
    {
        Cache::put($this->key($owner, $id), json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE), now()->addMinutes($minutes));
        $index = $this->index($owner);
        $name = null;
        foreach ($index as $entry) {
            if (($entry['id'] ?? null) === $id) {
                $name = $entry['name'] ?? null;
            }
        }
        $index = array_values(array_filter($index, fn ($c) => ($c['id'] ?? null) !== $id));
        array_unshift($index, ['id' => $id, 'title' => $about['title'] ?? null, 'name' => $name, 'scope' => $about['scope'] ?? null,
            'questions' => (int) ($about['questions'] ?? 0), 'search' => Str::limit(Str::lower((string) ($about['search'] ?? '')), 2000, ''), 'updated_at' => now()->toIso8601String()]);
        $this->keep($owner, array_slice($index, 0, self::INDEX), $minutes);
    }

    public function forget(string $owner, string $id): void
    {
        Cache::forget($this->key($owner, $id));
        $this->keep($owner, array_values(array_filter($this->index($owner), fn ($c) => ($c['id'] ?? null) !== $id)));
    }

    public function list(string $owner, ?string $scope = null, int $limit = 30): array
    {
        return $this->page($owner, $scope, $limit)['conversations'];
    }

    public function page(string $owner, ?string $scope, int $limit, ?string $before = null, ?string $query = null): array
    {
        $query = Str::lower(trim((string) $query));
        $alive = array_values(array_filter($this->index($owner), fn ($c) => ($c['scope'] ?? null) === $scope && Cache::has($this->key($owner, (string) $c['id']))
            && ($query === '' || str_contains(Str::lower((string) (($c['name'] ?? null) ?: ($c['title'] ?? ''))), $query) || str_contains((string) ($c['search'] ?? ''), $query))));
        $from = 0;
        if ($before !== null) {
            $at = array_search($before, array_column($alive, 'id'), true);
            $from = $at === false ? 0 : $at + 1;
        }
        $slice = array_slice($alive, $from, $limit);

        return ['conversations' => array_map(fn ($c) => self::item($c), $slice), 'next' => count($alive) > $from + $limit && $slice ? (string) end($slice)['id'] : null];
    }

    public function rename(string $owner, string $id, string $title): ?array
    {
        $index = $this->index($owner);
        foreach ($index as $i => $entry) {
            if (($entry['id'] ?? null) === $id && Cache::has($this->key($owner, $id))) {
                $index[$i]['name'] = trim($title) === '' ? null : Str::limit(trim($title), 80, '');
                $this->keep($owner, $index);

                return self::item($index[$i]);
            }
        }

        return null;
    }

    /** @return array{id: string, title: ?string, scope: ?string, updated_at: string, questions: int} */
    private static function item(array $entry): array
    {
        return ['id' => (string) $entry['id'], 'title' => ($entry['name'] ?? null) ?: ($entry['title'] ?? null), 'scope' => $entry['scope'] ?? null,
            'updated_at' => (string) ($entry['updated_at'] ?? ''), 'questions' => (int) ($entry['questions'] ?? 0)];
    }

    private function keep(string $owner, array $index, ?int $minutes = null): void
    {
        Cache::put($this->indexKey($owner), json_encode($index, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE), now()->addMinutes($minutes ?? (int) config('rekhu-assistant.conversation_minutes', 1440)));
    }

    private function index(string $owner): array
    {
        $stored = Cache::get($this->indexKey($owner));
        $index = is_string($stored) ? json_decode($stored, true) : $stored;

        return is_array($index) ? $index : [];
    }

    /** The same key as before stores existed, so conversations kept before an update open again. */
    private function key(string $owner, string $id): string
    {
        return 'rekhu-assistant:'.sha1($owner).':'.$id;
    }

    private function indexKey(string $owner): string
    {
        return 'rekhu-assistant:conversations:'.sha1($owner);
    }
}
