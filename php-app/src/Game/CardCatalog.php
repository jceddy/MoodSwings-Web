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
     * @return array{idsByName: array<string, int>, rowsById: array<int, array{name: string, rarity: string, color: string, draftPriorityScore: int}>}
     */
    public static function load(): array
    {
        // is_token = 0 (migration 0183, Chaos Draft's own 5 conjured-token
        // cards, issue #405) -- a token only ever exists once a chaos
        // effect actually puts it into play (BoardStateRepository's own
        // full `SELECT * FROM cards` -- no filter there -- still hydrates
        // it fine at that point). It's not a real catalog card a player
        // ever drafts, saves into a decklist, or sees on the stats page,
        // so every consumer of idsByName/rowsById here (DecklistParser's
        // name resolution, UserDecklistService's card-id validation,
        // CardStatsService's per-card rows, Tiered Rotisserie Draft's own
        // 'rarity' tiering) needs it excluded at the source rather than
        // each call site remembering to filter it out individually.
        $stmt = Connection::get()->query('SELECT id, name, rarity, color, draft_priority_score FROM cards WHERE is_token = 0');
        $idsByName = [];
        $rowsById = [];
        foreach ($stmt->fetchAll() as $row) {
            $id = (int) $row['id'];
            $idsByName[mb_strtolower($row['name'])] = $id;
            $rowsById[$id] = [
                'name' => $row['name'],
                'rarity' => $row['rarity'],
                'color' => $row['color'],
                'draftPriorityScore' => (int) $row['draft_priority_score'],
            ];
        }

        return ['idsByName' => $idsByName, 'rowsById' => $rowsById];
    }

    /**
     * Issue #359's own draft practice bots: the 5 build-around mythics'
     * curated partner-card lists (migration 0143's card_synergy_partners
     * table, see its own docblock for where this data comes from) --
     * mythic card id => that mythic's own 14 partner card ids. A bot that
     * has drafted a mythic reads its own list here to prioritize those
     * partners more highly for the rest of the draft (see
     * MoodSwings\Bot\BotPlayerService's draft-pick scoring).
     *
     * @return array<int, int[]>
     */
    public static function loadSynergyPartnersByMythicId(): array
    {
        $stmt = Connection::get()->query('SELECT mythic_card_id, partner_card_id FROM card_synergy_partners');
        $partnersByMythicId = [];
        foreach ($stmt->fetchAll() as $row) {
            $partnersByMythicId[(int) $row['mythic_card_id']][] = (int) $row['partner_card_id'];
        }

        return $partnersByMythicId;
    }

    /**
     * Set code + collector number for every catalog card, keyed by
     * catalog_card_id -- the same "lowest set_id row" picking logic as
     * serialize()'s own subquery below, factored out so CardStatsService's
     * allCardStats() (issue #315 follow-up) can look this up for the whole
     * catalog at once without serialize()'s heavier per-card row (rules
     * text, ability flags, etc. the stats page has no use for).
     *
     * @return array<int, array{set_code: ?string, collector_number: ?int}>
     */
    public static function loadSetInfo(): array
    {
        $stmt = Connection::get()->query(
            "SELECT cs1.card_id, s.code AS set_code, cs1.collector_number
             FROM card_sets cs1
             JOIN sets s ON s.id = cs1.set_id
             WHERE cs1.set_id = (SELECT MIN(cs2.set_id) FROM card_sets cs2 WHERE cs2.card_id = cs1.card_id)"
        );

        $result = [];
        foreach ($stmt->fetchAll() as $row) {
            $result[(int) $row['card_id']] = [
                'set_code' => $row['set_code'],
                'collector_number' => $row['collector_number'] !== null ? (int) $row['collector_number'] : null,
            ];
        }

        return $result;
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
     * format (issue #92 follow-up). Also includes rarity, which neither
     * of those two flows reads but the deck builder (issue #93) needs for
     * its own filter/sort/format-restriction controls.
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
                'rarity' => $row['rarity'],
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
