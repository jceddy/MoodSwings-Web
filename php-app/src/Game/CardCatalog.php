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
     * on the frontend -- every in-play-only flag is false/null.
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
        $stmt = Connection::get()->prepare("SELECT * FROM cards WHERE id IN ({$placeholders})");
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
