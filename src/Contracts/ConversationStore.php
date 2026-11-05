<?php

declare(strict_types=1);

namespace Rekhu\Assistant\Contracts;

/**
 * Where the assistant keeps its conversations. The package keeps them in the app's cache (CacheConversations); an app
 * that wants them in its own tables, with a list of a user's conversations in its own chat panel, writes a store of its
 * own and names it with RekhuAssistant::conversationsUsing().
 *
 * $owner is who the conversation belongs to ("App\Models\User:12", "visitor:…"); a store must never hand one owner's
 * conversation to another. $data is the package's own state of the conversation (messages, pending confirmations,
 * attachments), kept as given. $about says who and what it is, for your own columns.
 */
interface ConversationStore
{
    /** The conversation's data as it was saved, or null when there is none. */
    public function get(string $owner, string $id): ?array;

    /**
     * Saves the conversation for at least $minutes (a store of your own may keep it longer).
     *
     * @param  array{user: mixed, visitor: ?string, tenant: array, scope: ?string, title: ?string}  $about
     */
    public function put(string $owner, string $id, array $data, int $minutes, array $about): void;

    public function forget(string $owner, string $id): void;

    /**
     * The owner's conversations, newest first, for a chat panel: each ['id', 'title', 'scope', 'updated_at'].
     *
     * @return list<array{id: string, title: ?string, scope: ?string, updated_at: string}>
     */
    public function list(string $owner, ?string $scope = null, int $limit = 30): array;
}
