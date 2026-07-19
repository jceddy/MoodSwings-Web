<?php

declare(strict_types=1);

namespace MoodSwings\Game;

use MoodSwings\Database\Connection;
use MoodSwings\Game\Exceptions\GameStateException;

/**
 * Stateless catalog-loading/hydration helpers shared by GameService and
 * MoodSwings\Deck\UserDecklistService (issue #92) -- extracted from
 * GameService's own previously-private loadCardCatalog()/
 * serializeCatalogCards() so a saved decklist's card ids can be
 * name-resolved and hydrated for display the exact same way a game's own
 * decklist text or drafted pool already is, without UserDecklistService
 * depending on the whole of GameService. GameService's own two methods
 * of the same shape are now one-line delegations to these.
 */
final class CardCatalog
{
    /**
     * @return array{idsByName: array<string, int>, rowsById: array<int, array{name: string, rarity: string, color: string}>}
     */
    public static function load(): array
    {
        $stmt = Connection::get()->query('SELECT id, name, rarity, color FROM cards');
        $idsByName = [];
        $rowsById = [];
        foreach ($stmt->fetchAll() as $row) {
            $id = (int) $row['id'];
            $idsByName[mb_strtolower($row['name'])] = $id;
            $rowsById[$id] = ['name' => $row['name'], 'rarity' => $row['rarity'], 'color' => $row['color']];
        }

        return ['idsByName' => $idsByName, 'rowsById' => $rowsById];
    }

    /**
     * Catalog-only card view (no BoardState/game_cards row involved) shaped
     * to exactly the fields buildCardThumb()/openCardDetail() already read
     * on the frontend -- every in-play-only flag is false/null. Also
     * includes set_code and collector_number (a card's own Set/collector
     * number within it -- see migrations 0015/0039 -- both picked from
     * the same card_sets row, the lowest sets.id if a card ever belongs
     * to more than one, though every card belongs to exactly one, "MSW",
     * today), which buildCardThumb()/openCardDetail() don't read but the
     * Decks dialog's "Edit"/"Download" flows do, to reconstruct a saved
     * deck's own decklist text in DecklistParser's "1 Name (SET) NUMBER"
     * format (issue #92 follow-up).
     *
     * @param int[] $cardIds
     * @return array<int, array<string, mixed>>
     */
    public static function serialize(array $cardIds): array
    {
        if ($cardIds === []) {
            return [];
        }

        // Deduplicated for the query -- $cardIds itself may legally contain
        // the same catalog id twice (a custom pool can list "2 Charity"),
        // but a card's own row only needs fetching once regardless of how
        // many times it appears in the caller's list.
        $distinctCardIds = array_values(array_unique($cardIds));
        $placeholders = implode(',', array_fill(0, count($distinctCardIds), '?'));
        // The subquery's own WHERE picks each card's lowest set_id row
        // (a correlated MIN(), not a window function, so this works on
        // any MySQL version) -- both set_code and collector_number come
        // from that one card_sets row together, rather than two separate
        // scalar subqueries that could theoretically disagree.
        $stmt = Connection::get()->prepare(
            "SELECT c.*, cs.set_code, cs.collector_number
             FROM cards c
             LEFT JOIN (
                SELECT cs1.card_id, s.code AS set_code, cs1.collector_number
                FROM card_sets cs1
                JOIN sets s ON s.id = cs1.set_id
                WHERE cs1.set_id = (SELECT MIN(cs2.set_id) FROM card_sets cs2 WHERE cs2.card_id = cs1.card_id)
             ) cs ON cs.card_id = c.id
             WHERE c.id IN ({$placeholders})"
        );
        $stmt->execute($distinctCardIds);

        $rowsById = [];
        foreach ($stmt->fetchAll() as $row) {
            $rowsById[(int) $row['id']] = $row;
        }

        return array_map(function (int $cardId) use ($rowsById): array {
            $row = $rowsById[$cardId] ?? throw new GameStateException("No such card {$cardId}");

            return [
                'card_id' => $cardId,
                'catalog_card_id' => $cardId,
                'set_code' => $row['set_code'],
                'collector_number' => $row['collector_number'] !== null ? (int) $row['collector_number'] : null,
                'name' => $row['name'],
                'color' => $row['color'],
                'base_color' => $row['color'],
                'value' => (int) $row['base_value'],
                'base_value' => (int) $row['base_value'],
                'alt_value' => $row['alt_value'] !== null ? (int) $row['alt_value'] : null,
                'has_dice_value' => $row['alt_value'] !== null,
                'effect_key' => $row['effect_key'],
                'rules_text' => $row['rules_text'],
                'choice_fields' => [],
                'is_playable' => false,
                'is_suppressed' => false,
                'value_locked' => false,
                'is_creativity_copy' => false,
                'copy_simulation' => null,
            ];
        }, $cardIds);
    }
}
