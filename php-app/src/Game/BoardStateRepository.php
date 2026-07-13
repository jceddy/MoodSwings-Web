<?php

declare(strict_types=1);

namespace MoodSwings\Game;

use MoodSwings\Database\Connection;
use MoodSwings\Rules\BoardState;
use MoodSwings\Rules\EffectRegistry;
use PDO;

/**
 * Loads a BoardState from, and persists it back to, the `cards`/
 * `game_cards`/`game_players` tables. This is the only place the pure
 * in-memory rules engine (see src/Rules/) touches the database -- GameService
 * calls load() before resolving a play and save() after, so BoardState
 * itself never has to know it's backed by a database at all.
 *
 * save() always rewrites every one of a game's game_cards rows (a plain
 * UPDATE by each row's own id -- see save()'s own docblock) rather than
 * diffing what changed. With well under a few hundred cards per game (up to
 * 266 for a duel's two full 'one_of_each' decks) this is cheap, and it
 * sidesteps having to track which rows a given card effect touched --
 * BoardState itself never records that either, so a diff would mean
 * comparing full before/after state anyway.
 */
final class BoardStateRepository
{
    public function __construct(private readonly EffectRegistry $registry)
    {
    }

    public function load(int $gameId): BoardState
    {
        $pdo = Connection::get();

        $formatStmt = $pdo->prepare('SELECT format FROM games WHERE id = :game_id');
        $formatStmt->execute(['game_id' => $gameId]);
        $hasSeparateDecks = $formatStmt->fetchColumn() === 'duel';

        $playersStmt = $pdo->prepare(
            'SELECT id, team_id FROM game_players WHERE game_id = :game_id ORDER BY seat_order ASC'
        );
        $playersStmt->execute(['game_id' => $gameId]);
        $playerRows = $playersStmt->fetchAll();
        $playerIds = array_map(static fn (array $row) => (int) $row['id'], $playerRows);

        // Only Open Team Play ever sets team_id -- see BoardState::isTeammate().
        $teamIdByPlayer = [];
        foreach ($playerRows as $row) {
            if ($row['team_id'] !== null) {
                $teamIdByPlayer[(int) $row['id']] = (int) $row['team_id'];
            }
        }

        $catalog = [];
        foreach ($pdo->query('SELECT * FROM cards') as $row) {
            $catalog[(int) $row['id']] = $this->mapCatalogRow($row);
        }

        $cardsStmt = $pdo->prepare('SELECT * FROM game_cards WHERE game_id = :game_id');
        $cardsStmt->execute(['game_id' => $gameId]);
        $gameCards = $cardsStmt->fetchAll();

        // Every $cardId flowing through BoardState is really this card's
        // own per-game instance id (game_cards.id), not its catalog id --
        // a 'duel' game gives each player their own complete deck, so the
        // same catalog card can exist twice in one game. This map lets
        // BoardState::catalogRow() translate an instance id back to the
        // catalog row (name/color/value/rules text) it should use.
        $catalogCardIdFor = [];
        foreach ($gameCards as $row) {
            $catalogCardIdFor[(int) $row['id']] = (int) $row['card_id'];
        }

        $hands = [];
        $deckByOwnerPosition = [];
        $discard = [];
        $discardOwners = [];
        $inPlayRows = [];

        foreach ($gameCards as $row) {
            $cardId = (int) $row['id'];
            $ownerKey = $row['owner_game_player_id'] !== null ? (int) $row['owner_game_player_id'] : BoardState::SHARED_DECK_KEY;

            if ($row['zone'] === 'hand') {
                $hands[$ownerKey][] = $cardId;
            } elseif ($row['zone'] === 'deck') {
                $deckByOwnerPosition[$ownerKey][(int) $row['deck_position']] = $cardId;
            } elseif ($row['zone'] === 'discard') {
                // A discard-pile row's own owner_game_player_id (if any --
                // see BoardState::$discardOwners) is tracked separately
                // from the pile itself, which always stays one shared list
                // regardless of $hasSeparateDecks.
                $discard[] = $cardId;
                if ($row['owner_game_player_id'] !== null) {
                    $discardOwners[$cardId] = (int) $row['owner_game_player_id'];
                }
            } else {
                $inPlayRows[] = $row;
            }
        }

        foreach ($deckByOwnerPosition as $ownerKey => $positions) {
            ksort($positions);
            $deckByOwnerPosition[$ownerKey] = array_values($positions);
        }
        $deck = $hasSeparateDecks ? $deckByOwnerPosition : ($deckByOwnerPosition[BoardState::SHARED_DECK_KEY] ?? []);

        $state = new BoardState($catalog, $this->registry, $playerIds, $hands, $deck, $discard, $hasSeparateDecks, $discardOwners, $catalogCardIdFor, $teamIdByPlayer);

        foreach ($inPlayRows as $row) {
            $state->restoreMoodInPlay(
                (int) $row['id'],
                (int) $row['owner_game_player_id'],
                $row['copied_card_id'] !== null ? (int) $row['copied_card_id'] : null,
                (bool) $row['is_suppressed'],
                $row['suppression_expiry'],
                $row['suppression_source_game_card_id'] !== null ? (int) $row['suppression_source_game_card_id'] : null,
                $row['effect_state'] !== null ? json_decode((string) $row['effect_state'], true) : [],
            );
        }

        $roundStmt = $pdo->prepare(
            "SELECT current_turn_game_player_id, first_game_player_id, team_turn_1_game_player_id, plays_remaining, pending_play_grants, round_number, discarded_this_round FROM game_rounds
             WHERE game_id = :game_id AND status = 'in_progress'
             ORDER BY round_number DESC LIMIT 1"
        );
        $roundStmt->execute(['game_id' => $gameId]);
        $roundRow = $roundStmt->fetch();
        if ($roundRow !== false) {
            // pending_play_grants may be absent on older rows (e.g. before
            // any restricted grant existed this turn) -- in that case every
            // outstanding play is unconditional.
            $playGrants = $roundRow['pending_play_grants'] !== null
                ? json_decode((string) $roundRow['pending_play_grants'], true)
                : array_fill(0, (int) $roundRow['plays_remaining'], null);

            // Chivalry/Triumph care about whoever PERSONALLY took turn 1
            // this round -- for Open Team Play, that's
            // team_turn_1_game_player_id (the team's own live choice of
            // which member goes), NOT first_game_player_id, which for a
            // team game only identifies a representative member of
            // whichever TEAM went first (see GameService::startGame()'s
            // own comment), not necessarily the actual player who did.
            // first_game_player_id remains what every non-team game (and
            // a team game's opening round-freeze window, before either
            // team has decided anything) uses instead.
            $actualFirstPlayerId = $roundRow['team_turn_1_game_player_id'] !== null
                ? (int) $roundRow['team_turn_1_game_player_id']
                : (int) $roundRow['first_game_player_id'];

            $state->restoreTurnState(
                $roundRow['current_turn_game_player_id'] !== null ? (int) $roundRow['current_turn_game_player_id'] : null,
                $playGrants,
                $actualFirstPlayerId,
                (int) $roundRow['round_number'],
                (bool) $roundRow['discarded_this_round'],
            );
        }

        return $state;
    }

    public function save(int $gameId, BoardState $state): void
    {
        $pdo = Connection::get();

        // Every card's game_cards row already exists (created once, at
        // startGame() time -- nothing ever creates a new one mid-game), so
        // this only ever moves an existing row between zones. $cardId is
        // the row's own surrogate id (see load()'s $catalogCardIdFor), so
        // a plain UPDATE-by-id replaces the old upsert-by-(game_id,card_id)
        // -- and since suppression_source_game_card_id is now already a
        // real instance id rather than needing translation from a catalog
        // id, it can be written in this same pass instead of a second one.
        $update = $pdo->prepare(
            'UPDATE game_cards SET
                zone = :zone,
                owner_game_player_id = :owner,
                deck_position = :deck_position,
                copied_card_id = :copied_card_id,
                is_suppressed = :is_suppressed,
                suppression_expiry = :suppression_expiry,
                suppression_source_game_card_id = :suppression_source_id,
                effect_state = :effect_state
             WHERE id = :id AND game_id = :game_id'
        );

        $write = function (
            int $cardId,
            string $zone,
            ?int $owner,
            ?int $deckPosition,
            ?int $copiedCardId,
            bool $isSuppressed,
            ?string $suppressionExpiry,
            ?int $suppressionSourceId,
            array $effectState,
        ) use ($update, $gameId): void {
            $update->execute([
                'id' => $cardId,
                'game_id' => $gameId,
                'zone' => $zone,
                'owner' => $owner,
                'deck_position' => $deckPosition,
                'copied_card_id' => $copiedCardId,
                'is_suppressed' => $isSuppressed ? 1 : 0,
                'suppression_expiry' => $suppressionExpiry,
                'suppression_source_id' => $suppressionSourceId,
                'effect_state' => $effectState === [] ? null : json_encode($effectState),
            ]);
        };

        foreach ($state->playerOrder() as $playerId) {
            foreach ($state->hand($playerId) as $cardId) {
                $write($cardId, 'hand', $playerId, null, null, false, null, null, []);
            }
        }

        foreach ($state->decks() as $deckKey => $deckCards) {
            $owner = $deckKey === BoardState::SHARED_DECK_KEY ? null : $deckKey;
            foreach ($deckCards as $position => $cardId) {
                $write($cardId, 'deck', $owner, $position, null, false, null, null, []);
            }
        }

        foreach ($state->discardPile() as $cardId) {
            $write($cardId, 'discard', $state->discardOwnerOf($cardId), null, null, false, null, null, []);
        }

        foreach ($state->moodsInPlay() as $mood) {
            $write(
                $mood->cardId,
                'in_play',
                $mood->ownerId,
                null,
                $mood->copiedCardId,
                $mood->isSuppressed,
                $mood->suppressionExpiry,
                $mood->suppressionSourceCardId,
                $mood->effectState,
            );
        }
    }

    /** @return array{color:string,rarity:string,baseValue:int,altValue:?int,effectKey:string,hasToPlay:bool,hasWhileInPlay:bool,hasAfterPlaying:bool,rulesText:string} */
    private function mapCatalogRow(array $row): array
    {
        return [
            'color' => $row['color'],
            'rarity' => $row['rarity'],
            'baseValue' => (int) $row['base_value'],
            'altValue' => $row['alt_value'] !== null ? (int) $row['alt_value'] : null,
            'effectKey' => $row['effect_key'],
            'hasToPlay' => (bool) $row['has_to_play_ability'],
            'hasWhileInPlay' => (bool) $row['has_while_in_play_ability'],
            'hasAfterPlaying' => (bool) $row['has_after_playing_ability'],
            'rulesText' => $row['rules_text'],
        ];
    }
}
