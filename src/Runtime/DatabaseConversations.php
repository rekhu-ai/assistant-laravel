<?php

declare(strict_types=1);

namespace Rekhu\Assistant\Runtime;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Rekhu\Assistant\Contracts\ConversationHistory;
use Rekhu\Assistant\Contracts\ConversationStore;

/**
 * Conversations in the app's database, in the table of the package's migration (rekhu_assistant_conversations), kept
 * for conversations.keep_days after their last message, so a user finds them in the chat's list days later. The
 * owner is kept as a hash next to it; the package's state of the conversation is one JSON column, as given.
 */
final class DatabaseConversations implements ConversationHistory, ConversationStore
{
    public function get(string $owner, string $id): ?array
    {
        $row = $this->table()->where('owner_hash', sha1($owner))->where('id', $id)->where('expires_at', '>', now())->first();
        $data = $row ? json_decode((string) $row->data, true) : null;

        return is_array($data) ? $data : null;
    }

    public function put(string $owner, string $id, array $data, int $minutes, array $about): void
    {
        $now = now();
        $values = [
            'scope' => $about['scope'] ?? null,
            'title' => isset($about['title']) ? Str::limit((string) $about['title'], 250) : null,
            'questions' => (int) ($about['questions'] ?? 0),
            'search' => Str::limit(Str::lower((string) ($about['search'] ?? '')), 8000, ''),
            'data' => json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE),
            'expires_at' => $now->copy()->addMinutes(max($minutes, self::keepMinutes())),
            'updated_at' => $now,
        ];
        $updated = $this->table()->where('owner_hash', sha1($owner))->where('id', $id)->update($values);
        if (! $updated) {
            $this->table()->insert($values + ['owner_hash' => sha1($owner), 'id' => $id, 'name' => null, 'created_at' => $now]);
        }
    }

    public function forget(string $owner, string $id): void
    {
        $this->table()->where('owner_hash', sha1($owner))->where('id', $id)->delete();
    }

    public function list(string $owner, ?string $scope = null, int $limit = 30): array
    {
        return $this->page($owner, $scope, $limit)['conversations'];
    }

    public function page(string $owner, ?string $scope, int $limit, ?string $before = null, ?string $query = null): array
    {
        $rows = $this->table()->where('owner_hash', sha1($owner))->where('expires_at', '>', now())
            ->when($scope === null, fn ($q) => $q->whereNull('scope'), fn ($q) => $q->where('scope', $scope));
        if ($query !== null && trim($query) !== '') {
            $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], Str::lower(trim($query))).'%';
            $rows->where(fn ($q) => $q->whereRaw('LOWER(COALESCE(name, title, \'\')) LIKE ?', [$like])->orWhere('search', 'like', $like));
        }
        $cursor = self::cursor($before);
        if ($cursor) {
            [$at, $id] = $cursor;
            $rows->where(fn ($q) => $q->where('updated_at', '<', $at)->orWhere(fn ($q) => $q->where('updated_at', $at)->where('id', '<', $id)));
        }
        $found = $rows->orderByDesc('updated_at')->orderByDesc('id')->limit($limit + 1)->get();
        $items = $found->take($limit)->map(fn ($row) => self::item($row))->values()->all();
        $last = $found->count() > $limit ? $found[$limit - 1] : null;

        return ['conversations' => $items, 'next' => $last ? Carbon::parse($last->updated_at)->toIso8601String().'|'.$last->id : null];
    }

    public function rename(string $owner, string $id, string $title): ?array
    {
        $row = fn () => $this->table()->where('owner_hash', sha1($owner))->where('id', $id)->where('expires_at', '>', now());
        if (! $row()->exists()) {
            return null;
        }
        $title = trim($title);
        $row()->update(['name' => $title === '' ? null : Str::limit($title, 80, '')]);

        return self::item($row()->first());
    }

    /** The time and id of a 'next' cursor, or null for one that is not the package's. */
    private static function cursor(?string $before): ?array
    {
        if ($before === null || ! str_contains($before, '|')) {
            return null;
        }
        [$at, $id] = explode('|', $before, 2);
        try {
            return [Carbon::parse($at), $id];
        } catch (\Throwable) {
            return null;
        }
    }

    /** Removes the conversations kept past their time; the number removed. */
    public function prune(): int
    {
        return $this->table()->where('expires_at', '<=', now())->delete();
    }

    /** How long a conversation is kept after its last message, in minutes: conversations.keep_days. */
    public static function keepMinutes(): int
    {
        return max(1, (int) config('rekhu-assistant.conversations.keep_days', 90)) * 1440;
    }

    /** @return array{id: string, title: ?string, scope: ?string, updated_at: string, questions: int} */
    private static function item(object $row): array
    {
        return ['id' => (string) $row->id, 'title' => $row->name ?: $row->title, 'scope' => $row->scope, 'updated_at' => Carbon::parse($row->updated_at)->toIso8601String(), 'questions' => (int) $row->questions];
    }

    private function table(): Builder
    {
        return DB::connection(config('rekhu-assistant.conversations.connection'))->table(self::name());
    }

    /** The table's name: conversations.table, rekhu_assistant_conversations by default. */
    public static function name(): string
    {
        return (string) config('rekhu-assistant.conversations.table', 'rekhu_assistant_conversations');
    }
}
