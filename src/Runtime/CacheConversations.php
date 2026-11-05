<?php

declare(strict_types=1);

namespace Rekhu\Assistant\Runtime;

use Illuminate\Support\Facades\Cache;
use Rekhu\Assistant\Contracts\ConversationStore;

/**
 * Conversations in the app's cache, shared by all its servers, for conversation_minutes (24 hours by default). Kept as
 * JSON: only strings and arrays, whatever classes the app's cache store may unserialize. Each owner also has a short
 * index of their conversations, so a chat panel can list them.
 */
final class CacheConversations implements ConversationStore
{
    private const INDEX = 50;

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
        $index = array_values(array_filter($this->index($owner), fn ($c) => ($c['id'] ?? null) !== $id));
        array_unshift($index, ['id' => $id, 'title' => $about['title'] ?? null, 'scope' => $about['scope'] ?? null, 'updated_at' => now()->toIso8601String()]);
        Cache::put($this->indexKey($owner), json_encode(array_slice($index, 0, self::INDEX)), now()->addMinutes($minutes));
    }

    public function forget(string $owner, string $id): void
    {
        Cache::forget($this->key($owner, $id));
        Cache::put($this->indexKey($owner), json_encode(array_values(array_filter($this->index($owner), fn ($c) => ($c['id'] ?? null) !== $id))), now()->addMinutes((int) config('rekhu-assistant.conversation_minutes', 1440)));
    }

    public function list(string $owner, ?string $scope = null, int $limit = 30): array
    {
        // a conversation that expired from the cache is no longer listed
        $alive = array_filter($this->index($owner), fn ($c) => ($c['scope'] ?? null) === $scope && Cache::has($this->key($owner, (string) $c['id'])));

        return array_slice(array_values($alive), 0, $limit);
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
