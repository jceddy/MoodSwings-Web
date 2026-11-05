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
    /** The original Mood Swings set -- what every random pool is built from unless a game says otherwise. */
    public const ORIGINAL_SET_CODE = 'MSW';

    /**
     * Every set, with how many real (non-token) cards it holds. A custom set
     * (sets.is_custom) is only listed when $includeCustom -- i.e. for a player
     * who opted into custom content. $nonEmptyOnly additionally drops a set that
     * has no cards yet (what the New Game dialog offers).
     *
     * @return array<int, array{code: string, name: string, is_custom: bool, card_count: int}>
     */
    public static function sets(bool $includeCustom, bool $nonEmptyOnly = false): array
    {
        $stmt = Connection::get()->query(
            'SELECT s.code, s.name, s.is_custom, COUNT(c.id) AS card_count
             FROM sets s
             LEFT JOIN card_sets cs ON cs.set_id = s.id
             LEFT JOIN cards c ON c.id = cs.card_id AND c.is_token = 0
             GROUP BY s.id, s.code, s.name, s.is_custom
             ORDER BY s.is_custom, s.id'
        );

        $sets = [];
        foreach ($stmt->fetchAll() as $row) {
            if (((bool) $row['is_custom'] && !$includeCustom) || ($nonEmptyOnly && (int) $row['card_count'] === 0)) {
                continue;
            }
            $sets[] = [
                'code' => $row['code'],
                'name' => $row['name'],
                'is_custom' => (bool) $row['is_custom'],
                'card_count' => (int) $row['card_count'],
            ];
        }

        return $sets;
    }

    /**
     * The set code list a request asked for, validated: null/empty means the
     * original set only; codes are deduplicated and must name a real set; a
     * custom set additionally needs $allowCustom (the viewer's own opt-in --
     * a hidden set reads as unknown rather than admitting it exists).
     *
     * @param string[]|null $codes
     * @return string[]
     */
    public static function normalizeSetCodes(?array $codes, bool $allowCustom): array
    {
        if ($codes === null || $codes === []) {
            return [self::ORIGINAL_SET_CODE];
        }

        $known = [];
        foreach (self::sets($allowCustom) as $set) {
            $known[strtoupper($set['code'])] = $set['code'];
        }

        $normalized = [];
        foreach ($codes as $code) {
            $canonical = $known[strtoupper(trim((string) $code))] ?? throw new GameStateException('Unknown card set "' . $code . '"');
            if (!in_array($canonical, $normalized, true)) {
                $normalized[] = $canonical;
            }
        }

        return $normalized;
    }

    /** games.card_set_codes as a list: NULL (every game before custom sets, or one that never chose) is the original set only. @return string[] */
    public static function setCodesOfGame(array $gameRow): array
    {
        $stored = $gameRow['card_set_codes'] ?? null;
        if ($stored === null || $stored === '') {
            return [self::ORIGINAL_SET_CODE];
        }

        return array_values(array_filter(explode(',', (string) $stored), static fn (string $code): bool => $code !== ''));
    }

    /** The games.card_set_codes value to store: NULL when it is just the original set (so those games look exactly like they always did). @param string[] $codes */
    public static function encodeSetCodes(array $codes): ?string
    {
        return $codes === [self::ORIGINAL_SET_CODE] ? null : implode(',', $codes);
    }

    /** Whether any of $codes is a custom set. @param string[] $codes */
    public static function includesCustomSet(array $codes): bool
    {
        if ($codes === []) {
            return false;
        }

        $placeholders = implode(',', array_fill(0, count($codes), '?'));
        $stmt = Connection::get()->prepare("SELECT 1 FROM sets WHERE code IN ({$placeholders}) AND is_custom = 1 LIMIT 1");
        $stmt->execute(array_values($codes));

        return $stmt->fetchColumn() !== false;
    }

    /**
     * Ids of the real (non-token) cards in any of $codes' sets, ascending. A
     * reprint that is in several of them appears once.
     *
     * @param string[] $codes
     * @return int[]
     */
    public static function cardIdsInSets(array $codes): array
    {
        if ($codes === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($codes), '?'));
        $stmt = Connection::get()->prepare(
            "SELECT DISTINCT c.id
             FROM cards c
             JOIN card_sets cs ON cs.card_id = c.id
             JOIN sets s ON s.id = cs.set_id
             WHERE c.is_token = 0 AND s.code IN ({$placeholders})
             ORDER BY c.id"
        );
        $stmt->execute(array_values($codes));

        return array_map(intval(...), $stmt->fetchAll(\PDO::FETCH_COLUMN));
    }

    /**
     * Ids of the real cards that belong ONLY to custom sets -- the ones hidden
     * from a player who hasn't opted into custom content. (A custom set's
     * reprint of an original card is also in the original set, so it isn't.)
     *
     * @return int[]
     */
    public static function customOnlyCardIds(): array
    {
        $stmt = Connection::get()->query(
            'SELECT c.id
             FROM cards c
             WHERE c.is_token = 0
               AND EXISTS (SELECT 1 FROM card_sets cs JOIN sets s ON s.id = cs.set_id WHERE cs.card_id = c.id AND s.is_custom = 1)
               AND NOT EXISTS (SELECT 1 FROM card_sets cs JOIN sets s ON s.id = cs.set_id WHERE cs.card_id = c.id AND s.is_custom = 0)
             ORDER BY c.id'
        );

        return array_map(intval(...), $stmt->fetchAll(\PDO::FETCH_COLUMN));
    }

    /**
     * @param bool $includeCustom false drops the cards that are only in custom sets
     *        -- what a player who hasn't opted into custom content may see or type
     *        into a decklist. Engine-internal callers keep the default (true).
     * @return array{idsByName: array<string, int>, rowsById: array<int, array{name: string, rarity: string, color: string, draftPriorityScore: int}>}
     */
    public static function load(bool $includeCustom = true): array
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
        $hidden = $includeCustom ? [] : array_flip(self::customOnlyCardIds());
        foreach ($stmt->fetchAll() as $row) {
            $id = (int) $row['id'];
            if (isset($hidden[$id])) {
                continue;
            }
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
                'art_set_code' => $row['art_set_code'],
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
                'alt_value_active' => false,
                'is_creativity_copy' => false,
                'copy_simulation' => null,
            ];
        }, $cardIds);
    }
}
