<?php

declare(strict_types=1);

namespace MoodSwings\Game;

use MoodSwings\Database\Connection;
use MoodSwings\Rules\BoardState;
use MoodSwings\Rules\ChaosEffectRegistry;
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
    public function __construct(
        private readonly EffectRegistry $registry,
        private readonly ChaosEffectRegistry $chaosRegistry = new ChaosEffectRegistry(),
    ) {
    }

    public function load(int $gameId): BoardState
    {
        $pdo = Connection::get();

        $formatStmt = $pdo->prepare('SELECT format, deck_type FROM games WHERE id = :game_id');
        $formatStmt->execute(['game_id' => $gameId]);
        $gameRow = $formatStmt->fetch();
        // 'draft' (Quick Draft and future draft-style deck types, see
        // GameService's own "Quick Draft" docblock) reuses the Duel
        // engine's rules completely unchanged -- each player has their own
        // separate deck, exactly like 'duel' itself. A drafted deck_type
        // (issue #362) gives the same separate-deck treatment even under
        // 'team'/'closed_team' -- unlike every OTHER deck_type those two
        // formats support, a drafted deck is genuinely different content
        // per player, not one shared/identical pool everyone draws from
        // together, so it has to be checked independently of format here
        // (contrast every other deck_type, where format alone already
        // decides it).
        $hasSeparateDecks = in_array($gameRow['format'], ['duel', 'draft'], true)
            || in_array($gameRow['deck_type'], ['quick_draft', 'winston_draft', 'grid_draft', 'rotisserie_draft', 'tiered_rotisserie_draft', 'sealed_deck'], true);

        $playersStmt = $pdo->prepare(
            'SELECT id, team_id, resigned_at, banked_extra_plays FROM game_players WHERE game_id = :game_id ORDER BY seat_order ASC'
        );
        $playersStmt->execute(['game_id' => $gameId]);
        $playerRows = $playersStmt->fetchAll();
        $playerIds = array_map(static fn (array $row) => (int) $row['id'], $playerRows);

        // Only Open Team Play ever sets team_id -- see BoardState::isTeammate().
        $teamIdByPlayer = [];
        // See GameService::resignGame() / BoardState::isResigned() -- empty
        // for every game with no resignations.
        $resignedPlayerIds = [];
        // See BoardState::bankExtraPlay() -- empty for every game with no
        // outstanding Joy/Generosity plays banked.
        $bankedExtraPlays = [];
        foreach ($playerRows as $row) {
            if ($row['team_id'] !== null) {
                $teamIdByPlayer[(int) $row['id']] = (int) $row['team_id'];
            }
            if ($row['resigned_at'] !== null) {
                $resignedPlayerIds[] = (int) $row['id'];
            }
            if ($row['banked_extra_plays'] !== null) {
                $bankedExtraPlays[(int) $row['id']] = json_decode((string) $row['banked_extra_plays'], true);
            }
        }

        $catalog = [];
        foreach ($pdo->query('SELECT * FROM cards') as $row) {
            $catalog[(int) $row['id']] = $this->mapCatalogRow($row);
        }

        // Chaos Draft (issue #405): loaded unconditionally, the same way
        // $catalog itself is -- a tiny, cheap, game-independent read, so
        // there's no need to special-case it per deck_type the way
        // $hasSeparateDecks above has to.
        $chaosCatalog = [];
        foreach ($pdo->query('SELECT * FROM chaos_effects') as $row) {
            $chaosCatalog[(int) $row['id']] = $this->mapChaosCatalogRow($row);
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
        // Chaos Draft: an attached effect (game_cards.chaos_effect_id)
        // travels with a card through every zone it's ever in -- hand,
        // deck, discard, or in play -- for the rest of the game, not just
        // while it happens to be in play, so this is read for every row
        // here the same way $catalogCardIdFor is, not just $inPlayRows.
        $chaosEffectIdFor = [];
        foreach ($gameCards as $row) {
            $catalogCardIdFor[(int) $row['id']] = (int) $row['card_id'];
            if ($row['chaos_effect_id'] !== null) {
                $chaosEffectIdFor[(int) $row['id']] = (int) $row['chaos_effect_id'];
            }
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

        $state = new BoardState($catalog, $this->registry, $playerIds, $hands, $deck, $discard, $hasSeparateDecks, $discardOwners, $catalogCardIdFor, $teamIdByPlayer, $resignedPlayerIds, $bankedExtraPlays, $chaosCatalog, $this->chaosRegistry, $chaosEffectIdFor);

        foreach ($inPlayRows as $row) {
            $state->restoreMoodInPlay(
                (int) $row['id'],
                (int) $row['owner_game_player_id'],
                $row['copied_card_id'] !== null ? (int) $row['copied_card_id'] : null,
                $this->decodeSuppressions($row['suppressions']),
                $row['effect_state'] !== null ? json_decode((string) $row['effect_state'], true) : [],
            );
        }

        $roundStmt = $pdo->prepare(
            "SELECT current_turn_game_player_id, first_game_player_id, team_turn_1_game_player_id, plays_remaining, pending_play_grants, round_number, discarded_this_round, skip_scoring, skip_scoring_first_player_game_player_id, skip_scoring_source_card_id, skip_scoring_owner_game_player_id FROM game_rounds
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
                (bool) $roundRow['skip_scoring'],
                $roundRow['skip_scoring_first_player_game_player_id'] !== null ? (int) $roundRow['skip_scoring_first_player_game_player_id'] : null,
                $roundRow['skip_scoring_source_card_id'] !== null ? (int) $roundRow['skip_scoring_source_card_id'] : null,
                $roundRow['skip_scoring_owner_game_player_id'] !== null ? (int) $roundRow['skip_scoring_owner_game_player_id'] : null,
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
        // -- and since a suppression source id is now already a real
        // instance id rather than needing translation from a catalog id,
        // it can be written in this same pass instead of a second one.
        //
        // Chaos Draft (issue #405) is the one exception to "nothing ever
        // creates a new one mid-game": BoardState::spawnMoodInPlay()
        // (a token conjured into play by a chaos effect) hands out a
        // negative placeholder id specifically so this method can tell a
        // brand-new card apart from an ordinary one and $insertSpawned
        // below INSERTs a real row for it instead. A bug caught live: this
        // method can run more than once against the very same BoardState
        // within a single request (e.g. GameService::finishPlay() saves
        // once, then advanceTurn() saves again for the SAME $state to
        // persist computeFreshGrants()'s own side effect) -- a token's own
        // negative placeholder id never changes in memory (see
        // spawnMoodInPlay()'s own docblock), so without checking
        // $state->persistedCardIdFor() first, a second save() blindly
        // re-INSERTed a second row for a token the first save() had
        // already persisted, duplicating it. See
        // BoardState::recordSpawnedCardPersisted()'s own docblock.
        $update = $pdo->prepare(
            'UPDATE game_cards SET
                zone = :zone,
                owner_game_player_id = :owner,
                deck_position = :deck_position,
                copied_card_id = :copied_card_id,
                suppressions = :suppressions,
                effect_state = :effect_state,
                chaos_effect_id = :chaos_effect_id
             WHERE id = :id AND game_id = :game_id'
        );
        $insertSpawned = $pdo->prepare(
            'INSERT INTO game_cards (game_id, card_id, zone, owner_game_player_id, chaos_effect_id)
             VALUES (:game_id, :card_id, :zone, :owner, :chaos_effect_id)'
        );

        $write = function (
            int $cardId,
            string $zone,
            ?int $owner,
            ?int $deckPosition,
            ?int $copiedCardId,
            array $suppressions,
            array $effectState,
        ) use ($update, $insertSpawned, $gameId, $state, $pdo): void {
            $chaosEffectId = $state->chaosEffectId($cardId);
            $persistedId = $state->persistedCardIdFor($cardId);

            if ($persistedId < 0) {
                $insertSpawned->execute([
                    'game_id' => $gameId,
                    'card_id' => $state->catalogCardId($cardId),
                    'zone' => $zone,
                    'owner' => $owner,
                    'chaos_effect_id' => $chaosEffectId,
                ]);
                $state->recordSpawnedCardPersisted($cardId, (int) $pdo->lastInsertId());

                return;
            }

            $update->execute([
                'id' => $persistedId,
                'game_id' => $gameId,
                'zone' => $zone,
                'owner' => $owner,
                'deck_position' => $deckPosition,
                'copied_card_id' => $copiedCardId,
                'suppressions' => $this->encodeSuppressions($suppressions),
                'effect_state' => $effectState === [] ? null : json_encode($effectState),
                'chaos_effect_id' => $chaosEffectId,
            ]);
        };

        foreach ($state->playerOrder() as $playerId) {
            foreach ($state->hand($playerId) as $cardId) {
                $write($cardId, 'hand', $playerId, null, null, [], []);
            }
        }

        foreach ($state->decks() as $deckKey => $deckCards) {
            $owner = $deckKey === BoardState::SHARED_DECK_KEY ? null : $deckKey;
            foreach ($deckCards as $position => $cardId) {
                $write($cardId, 'deck', $owner, $position, null, [], []);
            }
        }

        foreach ($state->discardPile() as $cardId) {
            $write($cardId, 'discard', $state->discardOwnerOf($cardId), null, null, [], []);
        }

        foreach ($state->moodsInPlay() as $mood) {
            $write(
                $mood->cardId,
                'in_play',
                $mood->ownerId,
                null,
                $mood->copiedCardId,
                $mood->suppressions,
                $mood->effectState,
            );
        }

        // Same "always rewrite every row" convention as the game_cards
        // updates above, one UPDATE per seat regardless of whether that
        // player's own banked plays actually changed this request -- see
        // BoardState::bankExtraPlay()'s own docblock for why this is
        // tracked per game_player_id rather than piggybacked on a card's
        // effect_state the way it used to be.
        $updatePlayer = $pdo->prepare(
            'UPDATE game_players SET banked_extra_plays = :banked_extra_plays WHERE id = :id AND game_id = :game_id'
        );
        $bankedExtraPlays = $state->bankedExtraPlaysByPlayer();
        foreach ($state->playerOrder() as $playerId) {
            $banked = $bankedExtraPlays[$playerId] ?? [];
            $updatePlayer->execute([
                'id' => $playerId,
                'game_id' => $gameId,
                'banked_extra_plays' => $banked === [] ? null : json_encode($banked),
            ]);
        }
    }

    /**
     * @param array<int, array{expiry: string, sourceCardId: ?int}> $suppressions
     */
    private function encodeSuppressions(array $suppressions): ?string
    {
        if ($suppressions === []) {
            return null;
        }

        return json_encode(array_map(
            static fn (array $s) => ['expiry' => $s['expiry'], 'sourceCardId' => $s['sourceCardId']],
            $suppressions,
        ));
    }

    /** @return array<int, array{expiry: string, sourceCardId: ?int}> */
    private function decodeSuppressions(?string $json): array
    {
        if ($json === null) {
            return [];
        }

        return array_map(
            static fn (array $s) => ['expiry' => $s['expiry'], 'sourceCardId' => $s['sourceCardId'] !== null ? (int) $s['sourceCardId'] : null],
            json_decode($json, true),
        );
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

    /** @return array{effectKey:string,rarity:string,shape:string,rulesText:string} */
    private function mapChaosCatalogRow(array $row): array
    {
        return [
            'effectKey' => $row['effect_key'],
            'rarity' => $row['rarity'],
            'shape' => $row['shape'],
            'rulesText' => $row['rules_text'],
        ];
    }
}
