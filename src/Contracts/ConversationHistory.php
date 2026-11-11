<?php

declare(strict_types=1);

namespace Rekhu\Assistant\Contracts;

/**
 * A conversation store that also serves the chat's list of earlier conversations: pages of it, a search, a name the
 * user gives. Both stores of the package implement it; a store of your own may, and without it the list still works
 * through ConversationStore::list(), only without paging, search and renaming.
 *
 * Every item is ['id', 'title', 'scope', 'updated_at', 'questions'], where title is the user's name for it or else
 * their first question.
 */
interface ConversationHistory
{
    /**
     * A page of the owner's conversations, newest first. $before is the 'next' of the page before; $query searches the
     * titles and the user's questions, ignoring case.
     *
     * @return array{conversations: list<array{id: string, title: ?string, scope: ?string, updated_at: string, questions: int}>, next: ?string}
     */
    public function page(string $owner, ?string $scope, int $limit, ?string $before = null, ?string $query = null): array;

    /**
     * Names the owner's conversation; an empty title goes back to the first question. The item as the list shows it,
     * or null when the owner has no such conversation.
     *
     * @return array{id: string, title: ?string, scope: ?string, updated_at: string, questions: int}|null
     */
    public function rename(string $owner, string $id, string $title): ?array;
}
