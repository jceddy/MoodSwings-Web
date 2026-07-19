<?php

declare(strict_types=1);

namespace MoodSwings\Deck;

use MoodSwings\Friends\FriendshipService;
use MoodSwings\Game\CardCatalog;
use MoodSwings\Game\DecklistParser;
use MoodSwings\Repository\UserDecklistRepository;

/**
 * Saved user decklists (issue #92): lets a user save a decklist to their
 * account as a first-class, reusable object instead of only ever
 * supplying one scoped to a single games/game_players row via the
 * 'custom'/'custom_duel' deck_type flows (see GameService::createGame()/
 * submitCustomDuelDeck()). A saved decklist is created either from raw
 * decklist text (the same DecklistParser format those flows already use)
 * or from already-resolved card ids (the draft formats' own "Save deck"
 * button, which derives ids straight from its own drafted-cards selection
 * state client-side -- see web-static/js/game.js -- and posts directly to
 * the create route rather than going through GameService at all).
 */
final class UserDecklistService
{
    public function __construct(
        private readonly UserDecklistRepository $decklists,
        private readonly FriendshipService $friendships,
    ) {
    }

    /**
     * @param ?int[] $cardIds
     * @param ?int[] $sideboardCardIds
     */
    public function create(int $userId, string $name, ?string $decklistText, ?array $cardIds, ?array $sideboardCardIds, string $visibility): int
    {
        $this->assertValidVisibility($visibility);
        $name = $this->sanitizeName($name);
        [$cardIds, $sideboardCardIds] = $this->resolveCardIds($decklistText, $cardIds, $sideboardCardIds);

        return $this->decklists->create($userId, $name, $cardIds, $sideboardCardIds, $visibility);
    }

    /**
     * @param ?int[] $cardIds
     * @param ?int[] $sideboardCardIds
     */
    public function update(int $userId, int $decklistId, string $name, ?string $decklistText, ?array $cardIds, ?array $sideboardCardIds, string $visibility): void
    {
        $this->authorizeOwner($userId, $decklistId);
        $this->assertValidVisibility($visibility);
        $name = $this->sanitizeName($name);
        [$cardIds, $sideboardCardIds] = $this->resolveCardIds($decklistText, $cardIds, $sideboardCardIds);

        $this->decklists->update($decklistId, $name, $cardIds, $sideboardCardIds, $visibility);
    }

    public function delete(int $userId, int $decklistId): void
    {
        $this->authorizeOwner($userId, $decklistId);
        $this->decklists->delete($decklistId);
    }

    /**
     * The Decks dialog's own listing -- summaries only (id/name/counts/
     * visibility), never full card ids, matching how a decklist's actual
     * contents otherwise stay private until explicitly viewed (see
     * view() below). Friends with zero friends-visible decks of their own
     * are omitted entirely, per "a separate section for each friend that
     * HAS shared decks."
     *
     * @return array{own: array<int, array<string, mixed>>, friends: array<int, array{friend_id: int, friend_username: string, decklists: array<int, array<string, mixed>>}>}
     */
    public function listForViewer(int $userId): array
    {
        $own = array_map($this->summarize(...), $this->decklists->listForUser($userId));

        $friends = $this->friendships->listFriends($userId);
        $friendIds = array_map(static fn (array $f): int => (int) $f['friend_id'], $friends);

        $byFriendId = [];
        foreach ($friends as $friend) {
            $byFriendId[(int) $friend['friend_id']] = [
                'friend_id' => (int) $friend['friend_id'],
                'friend_username' => $friend['friend_username'],
                'decklists' => [],
            ];
        }
        foreach ($this->decklists->listFriendsVisibleForUsers($friendIds) as $row) {
            $byFriendId[(int) $row['user_id']]['decklists'][] = $this->summarize($row);
        }

        $friendsWithDecks = array_values(array_filter($byFriendId, static fn (array $f): bool => $f['decklists'] !== []));

        return ['own' => $own, 'friends' => $friendsWithDecks];
    }

    /**
     * Full contents for display -- authorized for the owner or, for a
     * 'friends'-visibility deck, any accepted friend of the owner. Cards
     * are hydrated via CardCatalog::serialize(), the exact same
     * catalog-only card shape buildCardThumb()/openCardDetail() already
     * render for a drafted pool.
     */
    public function view(int $viewerUserId, int $decklistId): array
    {
        $row = $this->authorizeViewer($viewerUserId, $decklistId);
        $cardIds = array_map(intval(...), (array) json_decode((string) $row['card_ids'], true));
        $sideboardCardIds = $row['sideboard_card_ids'] !== null
            ? array_map(intval(...), (array) json_decode((string) $row['sideboard_card_ids'], true))
            : [];

        return [
            'id' => (int) $row['id'],
            'name' => $row['name'],
            'visibility' => $row['visibility'],
            'owner_user_id' => (int) $row['user_id'],
            'cards' => CardCatalog::serialize($cardIds),
            'sideboard_cards' => CardCatalog::serialize($sideboardCardIds),
        ];
    }

    /**
     * The resolved {name, cardIds} shape GameService::createGame()/
     * submitCustomDuelDeck() consume in place of DecklistParser::parse()'s
     * own return value -- deliberately omits sideboardCardIds, since
     * neither of those two flows has any concept of a sideboard today.
     *
     * @return array{name: ?string, cardIds: int[]}
     */
    public function cardIdsForUse(int $viewerUserId, int $decklistId): array
    {
        $row = $this->authorizeViewer($viewerUserId, $decklistId);

        return [
            'name' => $row['name'],
            'cardIds' => array_map(intval(...), (array) json_decode((string) $row['card_ids'], true)),
        ];
    }

    private function authorizeOwner(int $userId, int $decklistId): array
    {
        $row = $this->decklists->find($decklistId);
        if ($row === null) {
            throw new DecklistNotFoundException("No such decklist {$decklistId}");
        }
        if ((int) $row['user_id'] !== $userId) {
            throw new NotAuthorizedToAccessDecklistException('You do not own this decklist.');
        }

        return $row;
    }

    private function authorizeViewer(int $viewerUserId, int $decklistId): array
    {
        $row = $this->decklists->find($decklistId);
        if ($row === null) {
            throw new DecklistNotFoundException("No such decklist {$decklistId}");
        }

        $ownerId = (int) $row['user_id'];
        if ($ownerId === $viewerUserId) {
            return $row;
        }
        if ($row['visibility'] === 'friends' && $this->friendships->areFriends($viewerUserId, $ownerId)) {
            return $row;
        }

        throw new NotAuthorizedToAccessDecklistException('You do not have access to this decklist.');
    }

    /**
     * @param ?int[] $cardIds
     * @param ?int[] $sideboardCardIds
     * @return array{0: int[], 1: ?int[]}
     */
    private function resolveCardIds(?string $decklistText, ?array $cardIds, ?array $sideboardCardIds): array
    {
        if ($decklistText !== null && trim($decklistText) !== '') {
            $parsed = (new DecklistParser(CardCatalog::load()['idsByName']))->parse($decklistText);

            return [$parsed['cardIds'], $parsed['sideboardCardIds'] !== [] ? $parsed['sideboardCardIds'] : null];
        }

        if ($cardIds === null || $cardIds === []) {
            throw new DecklistValidationException('The decklist has no cards in it.');
        }
        $cardIds = array_map(intval(...), $cardIds);
        $this->assertCardIdsExist($cardIds);

        if ($sideboardCardIds !== null && $sideboardCardIds !== []) {
            $sideboardCardIds = array_map(intval(...), $sideboardCardIds);
            $this->assertCardIdsExist($sideboardCardIds);
        } else {
            $sideboardCardIds = null;
        }

        return [$cardIds, $sideboardCardIds];
    }

    /** @param int[] $cardIds */
    private function assertCardIdsExist(array $cardIds): void
    {
        $rowsById = CardCatalog::load()['rowsById'];
        foreach ($cardIds as $cardId) {
            if (!isset($rowsById[$cardId])) {
                throw new DecklistValidationException("No such card {$cardId} in the catalog.");
            }
        }
    }

    private function sanitizeName(string $name): string
    {
        $name = trim($name);
        if ($name === '') {
            throw new DecklistValidationException('A deck name is required.');
        }

        return mb_substr($name, 0, 120);
    }

    private function assertValidVisibility(string $visibility): void
    {
        if (!in_array($visibility, ['private', 'friends'], true)) {
            throw new \InvalidArgumentException('Visibility must be "private" or "friends".');
        }
    }

    private function summarize(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'name' => $row['name'],
            'card_count' => count((array) json_decode((string) $row['card_ids'], true)),
            'sideboard_card_count' => $row['sideboard_card_ids'] !== null
                ? count((array) json_decode((string) $row['sideboard_card_ids'], true))
                : 0,
            'visibility' => $row['visibility'],
            'created_at' => $row['created_at'],
            'updated_at' => $row['updated_at'],
        ];
    }
}
