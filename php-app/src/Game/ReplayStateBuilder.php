<?php

declare(strict_types=1);

namespace MoodSwings\Game;

use MoodSwings\Database\Connection;
use MoodSwings\Game\Exceptions\GameStateException;
use MoodSwings\Rules\BoardState;
use MoodSwings\Rules\EffectRegistry;

/**
 * Reconstructs a completed game's BoardState as it looked at any point in
 * its history, for issue #240's "watch game replay" feature -- see
 * GameService::replayStateAsOf(). Never re-invokes any Effects/*.php
 * class; only ever replays the facts game_events already recorded (see
 * php-app/README.md's "Game log" section and BoardState's own
 * $pendingCardMoves/$pendingSuppressionChanges/$pendingEffectStateChanges
 * docblocks for what those facts are), so it's immune to future rule
 * changes and needs no randomness-injection seam for the six effects that
 * use real PHP randomness -- their outcomes are already concrete card ids
 * in the log.
 *
 * Two directions, both driven by the same event rows:
 *  - genesis() walks every event **in reverse** from the game's current
 *    (final) game_cards state, undoing each recorded fact, to recover
 *    "round-1 starting hands/decks, before any event exists" -- this
 *    naturally already reflects closed_team's blind initial card pass
 *    (submitInitialCardPass()/transferHandCards()), which completes
 *    strictly before game_events row #1 is ever logged, with no special
 *    casing needed.
 *  - stateAsOf() walks forward from genesis, re-applying the same facts
 *    up to (and including) one target event id.
 *
 * Zone membership (which hand/deck/discard/in-play a card sits in) is all
 * genesis needs to recover, so its own reverse walk only tracks bare card
 * ids per zone -- never suppression/effectState/who-owned-a-mood-while-it-
 * was-in-play, all of which are guaranteed to already be back to "not in
 * play at all" by the time every event has been undone (nothing can be in
 * play before the game's first play is ever logged). stateAsOf()'s own
 * forward walk, by contrast, tracks full in-play fidelity (owner,
 * copiedCardId, suppression, effectState) since that's exactly what
 * rendering a specific point in history needs.
 */
final class ReplayStateBuilder
{
    /** card_moves/entering-play zone name for "currently in play" -- distinct from the 'in_play' value game_cards.zone itself uses, see BoardState::$pendingCardMoves' own docblock. */
    private const PLAY_ZONE = 'play';

    public function __construct(private readonly EffectRegistry $registry)
    {
    }

    /**
     * $eventId of 0 is the sentinel for genesis (round-1 starting hands,
     * before any event exists) -- real game_events rows are auto-increment
     * ids starting at 1, so 0 can never collide with a genuine event.
     * Lets the frontend treat "the beginning" as just another step in the
     * same replayEvents list/getReplayGameState() call, rather than a
     * special case of its own.
     */
    public function stateAsOf(int $gameId, int $eventId): BoardState
    {
        if ($eventId === 0) {
            return $this->genesis($gameId);
        }

        $context = $this->loadContext($gameId);
        $events = $context['events'];

        $targetIndex = null;
        foreach ($events as $index => $event) {
            if ($event['id'] === $eventId) {
                $targetIndex = $index;
                break;
            }
        }
        if ($targetIndex === null) {
            throw new GameStateException("Event {$eventId} does not belong to game {$gameId}");
        }

        $genesis = $this->deriveGenesis($context['gameCards'], $events, $context['hasSeparateDecks']);

        $hands = $genesis['hands'];
        $decks = $genesis['decks'];
        $discard = [];
        $discardOwners = [];
        /** @var array<int, array{owner_id: int, copied_card_id: ?int, is_suppressed: bool, suppression_expiry: ?string, suppression_source_card_id: ?int, effect_state: array<string, mixed>}> */
        $inPlay = [];

        for ($i = 0; $i <= $targetIndex; $i++) {
            $this->applyEventForward($events[$i], $context['hasSeparateDecks'], $hands, $decks, $discard, $discardOwners, $inPlay);
        }

        return $this->assembleBoardState($context, $hands, $decks, $discard, $discardOwners, $inPlay);
    }

    /**
     * Round-1 starting hands/decks, before any event exists -- the same
     * reverse-derivation stateAsOf() uses to seed its own forward walk,
     * exposed publicly since it's a well-defined point in a completed
     * game's history in its own right (see this class's own docblock).
     * Its discard pile and in-play zone are always empty by construction
     * -- see deriveGenesis()'s own docblock.
     */
    public function genesis(int $gameId): BoardState
    {
        $context = $this->loadContext($gameId);
        $genesis = $this->deriveGenesis($context['gameCards'], $context['events'], $context['hasSeparateDecks']);

        return $this->assembleBoardState($context, $genesis['hands'], $genesis['decks'], [], [], []);
    }

    /** @return array{game: array<string, mixed>, events: array<int, array{id:int, event_type:string, acting_game_player_id:?int, card_id:?int, details: array<string, mixed>}>, catalog: array<int, array<string, mixed>>, gameCards: array<int, array<string, mixed>>, catalogCardIdFor: array<int,int>, playerIds: int[], teamIdByPlayer: array<int,int>, resignedPlayerIds: int[], hasSeparateDecks: bool} */
    private function loadContext(int $gameId): array
    {
        $game = $this->fetchGame($gameId);
        if ($game['status'] !== 'completed') {
            throw new GameStateException("Game {$gameId} isn't completed yet -- replay is only available once a game is over");
        }

        $gameCards = $this->loadGameCards($gameId);
        $catalogCardIdFor = [];
        foreach ($gameCards as $row) {
            $catalogCardIdFor[(int) $row['id']] = (int) $row['card_id'];
        }

        [$playerIds, $teamIdByPlayer, $resignedPlayerIds] = $this->loadPlayers($gameId);

        return [
            'game' => $game,
            'events' => $this->fetchEvents($gameId),
            'catalog' => $this->loadCatalog(),
            'gameCards' => $gameCards,
            'catalogCardIdFor' => $catalogCardIdFor,
            'playerIds' => $playerIds,
            'teamIdByPlayer' => $teamIdByPlayer,
            'resignedPlayerIds' => $resignedPlayerIds,
            'hasSeparateDecks' => self::hasSeparateDecks($game['format']),
        ];
    }

    /**
     * @param array{catalog: array<int, array<string, mixed>>, catalogCardIdFor: array<int,int>, playerIds: int[], teamIdByPlayer: array<int,int>, resignedPlayerIds: int[], hasSeparateDecks: bool} $context
     * @param array<int, int[]> $hands
     * @param array<int, int[]> $decks
     * @param int[] $discard
     * @param array<int, int> $discardOwners
     * @param array<int, array{owner_id:int, copied_card_id:?int, is_suppressed:bool, suppression_expiry:?string, suppression_source_card_id:?int, effect_state: array<string, mixed>}> $inPlay
     */
    private function assembleBoardState(array $context, array $hands, array $decks, array $discard, array $discardOwners, array $inPlay): BoardState
    {
        $hasSeparateDecks = $context['hasSeparateDecks'];
        $deck = $hasSeparateDecks ? $decks : ($decks[BoardState::SHARED_DECK_KEY] ?? []);
        $state = new BoardState(
            $context['catalog'],
            $this->registry,
            $context['playerIds'],
            $hands,
            $deck,
            $discard,
            $hasSeparateDecks,
            $discardOwners,
            $context['catalogCardIdFor'],
            $context['teamIdByPlayer'],
            $context['resignedPlayerIds'],
        );

        foreach ($inPlay as $cardId => $mood) {
            $state->restoreMoodInPlay(
                $cardId,
                $mood['owner_id'],
                $mood['copied_card_id'],
                $mood['is_suppressed'],
                $mood['suppression_expiry'],
                $mood['suppression_source_card_id'],
                $mood['effect_state'],
            );
        }

        return $state;
    }

    /**
     * @param array<int, array{id:int, event_type:string, acting_game_player_id:?int, card_id:?int, details: array<string, mixed>}> $events
     * @param array<int, int[]> &$hands playerId => card ids
     * @param array<int, int[]> &$decks deck key => card ids
     * @param int[] &$discard
     * @param array<int, int> &$discardOwners
     * @param array<int, array{owner_id:int, copied_card_id:?int, is_suppressed:bool, suppression_expiry:?string, suppression_source_card_id:?int, effect_state: array<string, mixed>}> &$inPlay
     */
    private function applyEventForward(
        array $event,
        bool $hasSeparateDecks,
        array &$hands,
        array &$decks,
        array &$discard,
        array &$discardOwners,
        array &$inPlay,
    ): void {
        $details = $event['details'];

        // A Duplicity repeat re-invokes the SAME card's effect (see
        // MoodPlayService::DUPLICITY_REPEAT_KEY/resolveDuplicityRepeatOffer()),
        // producing a second pending_decision_created event that ALSO
        // carries 'played_from' (read straight off the mood's own
        // permanently-persisted 'playedFromZone' effectState, unchanged
        // since the original play) -- guarding on "not already in play"
        // is what keeps that second tag from being (wrongly) treated as a
        // second, brand-new entering-play moment that would wipe out
        // whatever suppression/effectState the card had already
        // accumulated in play since the original event.
        $playedFrom = $details['played_from'] ?? null;
        if ($playedFrom !== null && $event['card_id'] !== null && !isset($inPlay[$event['card_id']])) {
            $cardId = $event['card_id'];
            $ownerId = $event['acting_game_player_id'];
            if ($playedFrom === 'hand') {
                $hands[$ownerId] = self::without($hands[$ownerId] ?? [], $cardId);
            } elseif ($playedFrom === 'discard') {
                $discard = self::without($discard, $cardId);
                unset($discardOwners[$cardId]);
            }
            $inPlay[$cardId] = [
                'owner_id' => $ownerId,
                'copied_card_id' => $details['copy_card_id'] ?? null,
                'is_suppressed' => false,
                'suppression_expiry' => null,
                'suppression_source_card_id' => null,
                'effect_state' => [],
            ];
        }

        foreach ($details['card_moves'] ?? [] as $move) {
            $this->applyCardMoveForward($move, $hasSeparateDecks, $hands, $decks, $discard, $discardOwners, $inPlay);
        }

        foreach ($details['ownership_changes'] ?? [] as $change) {
            if (isset($inPlay[$change['card_id']])) {
                $inPlay[$change['card_id']]['owner_id'] = $change['to_player_id'];
            }
        }

        foreach ($details['draws'] ?? [] as $draw) {
            $key = $hasSeparateDecks ? $draw['player_id'] : BoardState::SHARED_DECK_KEY;
            $decks[$key] = self::without($decks[$key] ?? [], $draw['card_id']);
            $hands[$draw['player_id']][] = $draw['card_id'];
        }

        foreach ($details['suppression_changes'] ?? [] as $change) {
            if (isset($inPlay[$change['card_id']])) {
                $inPlay[$change['card_id']]['is_suppressed'] = $change['is_suppressed'];
                $inPlay[$change['card_id']]['suppression_expiry'] = $change['suppression_expiry'];
                $inPlay[$change['card_id']]['suppression_source_card_id'] = $change['suppression_source_card_id'];
            }
        }

        foreach ($details['effect_state_changes'] ?? [] as $change) {
            if (!isset($inPlay[$change['card_id']])) {
                continue;
            }
            if ($change['cleared']) {
                unset($inPlay[$change['card_id']]['effect_state'][$change['key']]);
            } else {
                $inPlay[$change['card_id']]['effect_state'][$change['key']] = $change['value'];
            }
        }
    }

    /**
     * @param array{card_id:int, from_zone:string, to_zone:string, from_player_id:?int, to_player_id:?int} $move
     * @param array<int, int[]> &$hands
     * @param array<int, int[]> &$decks
     * @param int[] &$discard
     * @param array<int, int> &$discardOwners
     * @param array<int, array{owner_id:int, copied_card_id:?int, is_suppressed:bool, suppression_expiry:?string, suppression_source_card_id:?int, effect_state: array<string, mixed>}> &$inPlay
     */
    private function applyCardMoveForward(
        array $move,
        bool $hasSeparateDecks,
        array &$hands,
        array &$decks,
        array &$discard,
        array &$discardOwners,
        array &$inPlay,
    ): void {
        $cardId = $move['card_id'];

        // Whoever this card belonged to just before this move -- the mood's
        // own in-play owner (giveInPlayToPlayer() may have reassigned it
        // since it entered play, so this isn't always the player who
        // played it), the hand it came from, or -- moveDiscardToBottomOfDeck()'s
        // own case -- $discardOwners' existing entry, captured here before
        // it's removed below (from_zone='discard'/to_zone='deck' never
        // carries a from_player_id of its own; see BoardState::
        // moveDiscardToBottomOfDeck()).
        $originOwner = null;
        if ($move['from_zone'] === self::PLAY_ZONE) {
            $originOwner = $inPlay[$cardId]['owner_id'] ?? null;
            unset($inPlay[$cardId]);
        } elseif ($move['from_zone'] === 'hand') {
            $originOwner = $move['from_player_id'];
            $hands[$move['from_player_id']] = self::without($hands[$move['from_player_id']] ?? [], $cardId);
        } elseif ($move['from_zone'] === 'discard') {
            $originOwner = $discardOwners[$cardId] ?? null;
            $discard = self::without($discard, $cardId);
            unset($discardOwners[$cardId]);
        }

        if ($move['to_zone'] === 'discard') {
            $discard[] = $cardId;
            if ($originOwner !== null) {
                $discardOwners[$cardId] = $originOwner;
            }
        } elseif ($move['to_zone'] === 'hand') {
            $hands[$move['to_player_id']][] = $cardId;
        } elseif ($move['to_zone'] === 'deck') {
            $key = $hasSeparateDecks && $originOwner !== null ? $originOwner : BoardState::SHARED_DECK_KEY;
            $decks[$key][] = $cardId;
        }
    }

    /**
     * @param array<int, array<string, mixed>> $gameCards
     * @param array<int, array{id:int, event_type:string, acting_game_player_id:?int, card_id:?int, details: array<string, mixed>}> $events
     * @return array{hands: array<int, int[]>, decks: array<int, int[]>}
     */
    private function deriveGenesis(array $gameCards, array $events, bool $hasSeparateDecks): array
    {
        $hands = [];
        $decks = [];
        $discard = [];
        $inPlay = [];

        foreach ($gameCards as $row) {
            $cardId = (int) $row['id'];
            $ownerKey = $row['owner_game_player_id'] !== null ? (int) $row['owner_game_player_id'] : BoardState::SHARED_DECK_KEY;

            if ($row['zone'] === 'hand') {
                $hands[$ownerKey][] = $cardId;
            } elseif ($row['zone'] === 'deck') {
                $decks[$ownerKey][] = $cardId;
            } elseif ($row['zone'] === 'discard') {
                $discard[] = $cardId;
            } else {
                $inPlay[] = $cardId;
            }
        }

        // A Duplicity repeat re-invokes the SAME card's effect (see
        // MoodPlayService::DUPLICITY_REPEAT_KEY/resolveDuplicityRepeatOffer()),
        // producing a second event that ALSO carries 'played_from' (read
        // straight off the mood's own permanently-persisted
        // 'playedFromZone' effectState, unchanged since the original play)
        // -- only the earliest (lowest id) played_from event for a given
        // card is its real entering-play moment; every later recurrence
        // for the same card_id must be ignored when undoing, or the card
        // would get placed back into a hand/discard pile twice.
        $firstPlayedFromEventId = [];
        foreach ($events as $event) {
            $cardId = $event['card_id'];
            if ($cardId !== null && ($event['details']['played_from'] ?? null) !== null && !isset($firstPlayedFromEventId[$cardId])) {
                $firstPlayedFromEventId[$cardId] = $event['id'];
            }
        }

        for ($i = count($events) - 1; $i >= 0; $i--) {
            $this->unapplyEvent($events[$i], $hasSeparateDecks, $hands, $decks, $discard, $inPlay, $firstPlayedFromEventId);
        }

        return ['hands' => $hands, 'decks' => $decks];
    }

    /**
     * @param array{id:int, event_type:string, acting_game_player_id:?int, card_id:?int, details: array<string, mixed>} $event
     * @param array<int, int[]> &$hands
     * @param array<int, int[]> &$decks
     * @param int[] &$discard
     * @param int[] &$inPlay
     * @param array<int, int> $firstPlayedFromEventId card_id => the one event id whose 'played_from' tag is its real entering-play moment -- see deriveGenesis()'s own docblock comment above.
     */
    private function unapplyEvent(array $event, bool $hasSeparateDecks, array &$hands, array &$decks, array &$discard, array &$inPlay, array $firstPlayedFromEventId): void
    {
        $details = $event['details'];

        foreach (array_reverse($details['draws'] ?? []) as $draw) {
            $hands[$draw['player_id']] = self::without($hands[$draw['player_id']] ?? [], $draw['card_id']);
            $key = $hasSeparateDecks ? $draw['player_id'] : BoardState::SHARED_DECK_KEY;
            $decks[$key][] = $draw['card_id'];
        }

        foreach (array_reverse($details['card_moves'] ?? []) as $move) {
            $this->unapplyCardMove($move, $hasSeparateDecks, $hands, $decks, $discard, $inPlay);
        }

        $playedFrom = $details['played_from'] ?? null;
        if ($playedFrom !== null && $event['card_id'] !== null && $firstPlayedFromEventId[$event['card_id']] === $event['id']) {
            $cardId = $event['card_id'];
            $ownerId = $event['acting_game_player_id'];
            $inPlay = self::without($inPlay, $cardId);
            if ($playedFrom === 'hand') {
                $hands[$ownerId][] = $cardId;
            } elseif ($playedFrom === 'discard') {
                $discard[] = $cardId;
            }
        }
    }

    /**
     * @param array{card_id:int, from_zone:string, to_zone:string, from_player_id:?int, to_player_id:?int} $move
     * @param array<int, int[]> &$hands
     * @param array<int, int[]> &$decks
     * @param int[] &$discard
     * @param int[] &$inPlay
     */
    private function unapplyCardMove(array $move, bool $hasSeparateDecks, array &$hands, array &$decks, array &$discard, array &$inPlay): void
    {
        $cardId = $move['card_id'];

        if ($move['to_zone'] === 'discard') {
            $discard = self::without($discard, $cardId);
        } elseif ($move['to_zone'] === 'hand') {
            $hands[$move['to_player_id']] = self::without($hands[$move['to_player_id']] ?? [], $cardId);
        } elseif ($move['to_zone'] === 'deck') {
            $decks = self::withoutFromAnyDeck($decks, $cardId);
        }

        if ($move['from_zone'] === self::PLAY_ZONE) {
            $inPlay[] = $cardId;
        } elseif ($move['from_zone'] === 'hand') {
            $hands[$move['from_player_id']][] = $cardId;
        } elseif ($move['from_zone'] === 'discard') {
            $discard[] = $cardId;
        }
    }

    /** @param int[] $list @return int[] */
    private static function without(array $list, int $cardId): array
    {
        $index = array_search($cardId, $list, true);
        if ($index !== false) {
            unset($list[$index]);
        }

        return array_values($list);
    }

    /** @param array<int, int[]> $decks @return array<int, int[]> */
    private static function withoutFromAnyDeck(array $decks, int $cardId): array
    {
        foreach ($decks as $key => $cards) {
            $index = array_search($cardId, $cards, true);
            if ($index !== false) {
                unset($cards[$index]);
                $decks[$key] = array_values($cards);

                return $decks;
            }
        }

        return $decks;
    }

    /** @return array<string, mixed> */
    private function fetchGame(int $gameId): array
    {
        $stmt = Connection::get()->prepare('SELECT status, format FROM games WHERE id = :game_id');
        $stmt->execute(['game_id' => $gameId]);
        $game = $stmt->fetch();
        if ($game === false) {
            throw new GameStateException("Game {$gameId} not found");
        }

        return $game;
    }

    /** 'draft' (Quick Draft/Winston/Grid Draft) reuses the Duel engine's own separate-deck-per-player rules -- see BoardStateRepository::load()'s identical check. */
    private static function hasSeparateDecks(string $format): bool
    {
        return in_array($format, ['duel', 'draft'], true);
    }

    /** @return array{0: int[], 1: array<int,int>, 2: int[]} playerIds, teamIdByPlayer, resignedPlayerIds */
    private function loadPlayers(int $gameId): array
    {
        $stmt = Connection::get()->prepare(
            'SELECT id, team_id, resigned_at FROM game_players WHERE game_id = :game_id ORDER BY seat_order ASC'
        );
        $stmt->execute(['game_id' => $gameId]);
        $rows = $stmt->fetchAll();

        $playerIds = [];
        $teamIdByPlayer = [];
        $resignedPlayerIds = [];
        foreach ($rows as $row) {
            $playerId = (int) $row['id'];
            $playerIds[] = $playerId;
            if ($row['team_id'] !== null) {
                $teamIdByPlayer[$playerId] = (int) $row['team_id'];
            }
            if ($row['resigned_at'] !== null) {
                $resignedPlayerIds[] = $playerId;
            }
        }

        return [$playerIds, $teamIdByPlayer, $resignedPlayerIds];
    }

    /** @return array<int, array<string, mixed>> catalog id => catalog row, same shape as BoardStateRepository::mapCatalogRow() */
    private function loadCatalog(): array
    {
        $catalog = [];
        foreach (Connection::get()->query('SELECT * FROM cards') as $row) {
            $catalog[(int) $row['id']] = [
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

        return $catalog;
    }

    /** @return array<int, array<string, mixed>> */
    private function loadGameCards(int $gameId): array
    {
        $stmt = Connection::get()->prepare('SELECT * FROM game_cards WHERE game_id = :game_id');
        $stmt->execute(['game_id' => $gameId]);

        return $stmt->fetchAll();
    }

    /** @return array<int, array{id:int, event_type:string, acting_game_player_id:?int, card_id:?int, details: array<string, mixed>}> ordered by id ASC */
    private function fetchEvents(int $gameId): array
    {
        $stmt = Connection::get()->prepare(
            'SELECT id, event_type, acting_game_player_id, card_id, details FROM game_events WHERE game_id = :game_id ORDER BY id ASC'
        );
        $stmt->execute(['game_id' => $gameId]);

        return array_map(
            static fn (array $row): array => [
                'id' => (int) $row['id'],
                'event_type' => $row['event_type'],
                'acting_game_player_id' => $row['acting_game_player_id'] !== null ? (int) $row['acting_game_player_id'] : null,
                'card_id' => $row['card_id'] !== null ? (int) $row['card_id'] : null,
                'details' => $row['details'] !== null ? json_decode((string) $row['details'], true) : [],
            ],
            $stmt->fetchAll(),
        );
    }
}
