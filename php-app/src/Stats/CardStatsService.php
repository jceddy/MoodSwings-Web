<?php

declare(strict_types=1);

namespace MoodSwings\Stats;

use MoodSwings\Database\Connection;
use MoodSwings\Game\CardCatalog;

/**
 * Server-wide card/draft statistics (issue #315), keyed by catalog card
 * id (never a per-game instance id -- see GameService::serializeCard()'s
 * own catalog_card_id field / BoardState::catalogCardId()). Every stat is
 * written incrementally, at the moment it happens, into the `card_stats`
 * table (migration 0070) -- the same lazy-create `ON DUPLICATE KEY
 * UPDATE` convention GameService::bumpLifetimeStats() already uses for
 * `user_lifetime_stats` -- rather than computed lazily by reading
 * historical game data later, since a completed game is permanently
 * deleted after 7 days (GameService::deleteStaleCompletedGames()) and
 * Winston/Grid Draft's own pick state (draft_winston_state/
 * draft_grid_state) is deleted the instant each draft finishes: by the
 * time anyone looked at a stats page, there would be nothing left to
 * read from.
 *
 * Two independent groups of stats, hooked in from two different places
 * in GameService for two different reasons:
 *
 * - Deck-membership/played-card win rates (recordGameCompletion()) are
 *   only knowable once a game's outcome is decided, so they're hooked
 *   into recordGameCompletionStats() -- the one method every
 *   game-completion code path already funnels through.
 * - Draft pick-position signal (recordQuickDraftPick()/
 *   recordWinstonDraftPick()/recordGridDraftPick()) is knowable the
 *   instant a pick happens, independent of the eventual game/match
 *   outcome (same as 17lands' own ATA, which is a pure draft signal),
 *   so each is called directly from its own pick-submission method
 *   (submitQuickDraftPick()/submitWinstonDraftPick()/
 *   submitGridDraftPick()) rather than deferred to completion time --
 *   deferring wouldn't even be possible for Winston/Grid Draft, whose
 *   own pick state is gone by the time their match completes.
 *
 * Each draft format's own "how early was this taken" signal is measured
 * on a different, format-native scale (Quick Draft's round/stage
 * ordinal, Winston Draft's pile size at the moment of taking, Grid
 * Draft's round number) -- never compared across formats, so each gets
 * its own sum/count column pair rather than one shared value.
 */
final class CardStatsService
{
    /**
     * @param int[] $winningUserIds
     * @param int[] $losingUserIds
     */
    public function recordGameCompletion(int $gameId, array $winningUserIds, array $losingUserIds): void
    {
        $pdo = Connection::get();

        $gamePlayerStmt = $pdo->prepare('SELECT id, user_id FROM game_players WHERE game_id = :game_id');
        $gamePlayerStmt->execute(['game_id' => $gameId]);
        $userIdByGamePlayerId = [];
        foreach ($gamePlayerStmt->fetchAll() as $row) {
            $userIdByGamePlayerId[(int) $row['id']] = (int) $row['user_id'];
        }
        if ($userIdByGamePlayerId === []) {
            return;
        }

        /** @var array<int, array{in_deck:int, in_won_deck:int, played:int, played_in_won_game:int}> $deltas */
        $deltas = [];
        $bump = function (int $catalogCardId, string $key) use (&$deltas): void {
            $deltas[$catalogCardId] ??= ['in_deck' => 0, 'in_won_deck' => 0, 'played' => 0, 'played_in_won_game' => 0];
            $deltas[$catalogCardId][$key]++;
        };

        // Deck membership -- whoever currently owns a card, for every
        // format: pre-assigned decks (duel/draft formats) have this set
        // for their whole deck from startGame() onward; shared-pool
        // formats only count cards actually drawn by that player (an
        // undrawn deck card, still owner_game_player_id IS NULL at game
        // end, was never really part of their realized deck). DISTINCT
        // because a deck can legally contain duplicate copies of a
        // catalog card (see issue #109) -- a duplicate should still only
        // count once toward "how many decks this card ended up in".
        $deckStmt = $pdo->prepare(
            'SELECT DISTINCT owner_game_player_id, card_id FROM game_cards
             WHERE game_id = :game_id AND owner_game_player_id IS NOT NULL'
        );
        $deckStmt->execute(['game_id' => $gameId]);
        foreach ($deckStmt->fetchAll() as $row) {
            $userId = $userIdByGamePlayerId[(int) $row['owner_game_player_id']] ?? null;
            if ($userId === null) {
                continue;
            }
            $catalogCardId = (int) $row['card_id'];
            $bump($catalogCardId, 'in_deck');
            if (in_array($userId, $winningUserIds, true)) {
                $bump($catalogCardId, 'in_won_deck');
            }
        }

        // Actually played -- game_cards.id -> game_cards.card_id is the
        // catalog id (see migration 0013's own docblock on that
        // distinction). DISTINCT for the same reason as above: a card
        // played twice in one game (e.g. via Duplicity's repeat
        // mechanic) still only counts once toward "was this card played
        // in this game".
        $playedStmt = $pdo->prepare(
            "SELECT DISTINCT ge.acting_game_player_id, gc.card_id
             FROM game_events ge
             JOIN game_cards gc ON gc.id = ge.card_id
             WHERE ge.game_id = :game_id AND ge.event_type = 'mood_played'"
        );
        $playedStmt->execute(['game_id' => $gameId]);
        foreach ($playedStmt->fetchAll() as $row) {
            $userId = $userIdByGamePlayerId[(int) $row['acting_game_player_id']] ?? null;
            if ($userId === null) {
                continue;
            }
            $catalogCardId = (int) $row['card_id'];
            $bump($catalogCardId, 'played');
            if (in_array($userId, $winningUserIds, true)) {
                $bump($catalogCardId, 'played_in_won_game');
            }
        }

        if ($deltas === []) {
            return;
        }

        $stmt = $pdo->prepare(
            'INSERT INTO card_stats (catalog_card_id, times_in_deck, times_in_won_deck, times_played, times_played_in_won_game)
             VALUES (:id, :in_deck, :in_won_deck, :played, :played_won)
             ON DUPLICATE KEY UPDATE
                 times_in_deck = times_in_deck + VALUES(times_in_deck),
                 times_in_won_deck = times_in_won_deck + VALUES(times_in_won_deck),
                 times_played = times_played + VALUES(times_played),
                 times_played_in_won_game = times_played_in_won_game + VALUES(times_played_in_won_game)'
        );
        foreach ($deltas as $catalogCardId => $delta) {
            $stmt->execute([
                'id' => $catalogCardId,
                'in_deck' => $delta['in_deck'],
                'in_won_deck' => $delta['in_won_deck'],
                'played' => $delta['played'],
                'played_won' => $delta['played_in_won_game'],
            ]);
        }
    }

    /**
     * Quick Draft's own pick-position ordinal: lower means fresher/
     * earlier (round 1 stage 1 is the very first pick anyone makes on
     * any pile; higher rounds/stages have been round-tripped through
     * more players already). $catalogCardIds is whatever was just kept
     * at this one (round, stage) -- see submitQuickDraftPick().
     *
     * @param int[] $catalogCardIds
     */
    public function recordQuickDraftPick(array $catalogCardIds, int $roundNumber, int $stageNumber, int $playerCount): void
    {
        $position = ($roundNumber - 1) * $playerCount + $stageNumber;
        $this->bumpPickPosition($catalogCardIds, 'quick_draft_pick_position_sum', 'quick_draft_pick_position_count', $position);
    }

    /**
     * Winston Draft's own "how early" proxy: pile size at the moment of
     * taking (a small pile means it was taken fresh; a large one means
     * it was passed around and grew before finally being taken). Also
     * used for the pile-3-decline forced deck-draw, with a pile size of
     * 1 (always freshest, since nobody else has seen it) -- see
     * submitWinstonDraftPick().
     *
     * @param int[] $catalogCardIds
     */
    public function recordWinstonDraftPick(array $catalogCardIds, int $pileSize): void
    {
        $this->bumpPickPosition($catalogCardIds, 'winston_draft_pick_pile_size_sum', 'winston_draft_pick_pile_size_count', $pileSize);
    }

    /**
     * Grid Draft's own "how early" proxy: the round it was taken in --
     * see submitGridDraftPick().
     *
     * @param int[] $catalogCardIds
     */
    public function recordGridDraftPick(array $catalogCardIds, int $round): void
    {
        $this->bumpPickPosition($catalogCardIds, 'grid_draft_pick_round_sum', 'grid_draft_pick_round_count', $round);
    }

    /** @param int[] $catalogCardIds @param string $sumColumn/$countColumn always one of card_stats' own column names, never user input -- safe to interpolate */
    private function bumpPickPosition(array $catalogCardIds, string $sumColumn, string $countColumn, int $position): void
    {
        if ($catalogCardIds === []) {
            return;
        }

        $stmt = Connection::get()->prepare(
            "INSERT INTO card_stats (catalog_card_id, {$sumColumn}, {$countColumn})
             VALUES (:id, :position, 1)
             ON DUPLICATE KEY UPDATE
                 {$sumColumn} = {$sumColumn} + VALUES({$sumColumn}),
                 {$countColumn} = {$countColumn} + 1"
        );
        foreach ($catalogCardIds as $catalogCardId) {
            $stmt->execute(['id' => (int) $catalogCardId, 'position' => $position]);
        }
    }

    /**
     * The stats page's own read path -- every catalog card (via
     * CardCatalog::load(), the same 55-card source GameService/
     * UserDecklistService already share), each with its recorded stats
     * or all-zero/null defaults for a card nothing has happened to yet
     * (same "no row yet" handling as GameService::lifetimeStatsFor()).
     * Deliberately no minimum-sample-size filtering -- every card is
     * shown with its raw counts alongside any rate/average, so a
     * low-sample stat is visible as such rather than hidden or flagged.
     *
     * @return array<int, array{catalog_card_id:int, name:string, rarity:string, color:string, times_in_deck:int, deck_win_rate:?float, times_played:int, play_win_rate:?float, quick_draft:array{average:?float, count:int}, winston_draft:array{average:?float, count:int}, grid_draft:array{average:?float, count:int}}>
     */
    public function allCardStats(): array
    {
        $catalog = CardCatalog::load()['rowsById'];

        $statsByCardId = [];
        foreach (Connection::get()->query('SELECT * FROM card_stats')->fetchAll() as $row) {
            $statsByCardId[(int) $row['catalog_card_id']] = $row;
        }

        $result = [];
        foreach ($catalog as $catalogCardId => $card) {
            $row = $statsByCardId[$catalogCardId] ?? null;
            $timesInDeck = $row !== null ? (int) $row['times_in_deck'] : 0;
            $timesInWonDeck = $row !== null ? (int) $row['times_in_won_deck'] : 0;
            $timesPlayed = $row !== null ? (int) $row['times_played'] : 0;
            $timesPlayedInWonGame = $row !== null ? (int) $row['times_played_in_won_game'] : 0;

            $result[] = [
                'catalog_card_id' => $catalogCardId,
                'name' => $card['name'],
                'rarity' => $card['rarity'],
                'color' => $card['color'],
                'times_in_deck' => $timesInDeck,
                'deck_win_rate' => $timesInDeck > 0 ? round($timesInWonDeck / $timesInDeck, 3) : null,
                'times_played' => $timesPlayed,
                'play_win_rate' => $timesPlayed > 0 ? round($timesPlayedInWonGame / $timesPlayed, 3) : null,
                'quick_draft' => self::averagePick($row, 'quick_draft_pick_position_sum', 'quick_draft_pick_position_count'),
                'winston_draft' => self::averagePick($row, 'winston_draft_pick_pile_size_sum', 'winston_draft_pick_pile_size_count'),
                'grid_draft' => self::averagePick($row, 'grid_draft_pick_round_sum', 'grid_draft_pick_round_count'),
            ];
        }

        usort($result, static fn (array $a, array $b): int => $a['name'] <=> $b['name']);

        return $result;
    }

    /** @return array{average:?float, count:int} */
    private static function averagePick(?array $row, string $sumColumn, string $countColumn): array
    {
        $count = $row !== null ? (int) $row[$countColumn] : 0;
        $sum = $row !== null ? (int) $row[$sumColumn] : 0;

        return [
            'average' => $count > 0 ? round($sum / $count, 2) : null,
            'count' => $count,
        ];
    }
}
