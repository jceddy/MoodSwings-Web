<?php

declare(strict_types=1);

namespace MoodSwings\Rules\Effects\Custom;

use MoodSwings\Rules\BoardState;
use MoodSwings\Rules\PendingDecisionRequest;

/**
 * Small board queries shared by the community custom set's effects (see
 * MoodSwings\Rules\DefaultEffectRegistry's "Emotional Baggage" block). Kept as plain
 * static helpers so each card's own class reads as its rules text.
 */
final class CustomEffectSupport
{
    /** How many moods in play (any owner) have one of $colors, by their current color. @param string[] $colors */
    public static function countMoodsOfColors(BoardState $state, array $colors): int
    {
        $count = 0;
        foreach ($state->moodsInPlay() as $mood) {
            if (in_array($state->colorOf($mood->cardId), $colors, true)) {
                $count++;
            }
        }

        return $count;
    }

    /** Every active player other than $playerId (a teammate counts: "each other player"). @return int[] */
    public static function otherPlayerIds(BoardState $state, int $playerId): array
    {
        return array_values(array_filter($state->activePlayerOrder(), static fn (int $id): bool => $id !== $playerId));
    }

    /** Every active opponent of $playerId -- never a teammate, never $playerId. @return int[] */
    public static function opponentIds(BoardState $state, int $playerId): array
    {
        return array_values(array_filter(
            self::otherPlayerIds($state, $playerId),
            static fn (int $id): bool => !$state->isTeammate($playerId, $id),
        ));
    }

    /** The moods tied for $playerId's own highest value (empty when they have none). @return int[] */
    public static function highestValueMoodIds(BoardState $state, int $playerId): array
    {
        $highest = null;
        foreach ($state->moodsOwnedBy($playerId) as $mood) {
            $value = $state->valueOf($mood->cardId);
            if ($highest === null || $value > $highest) {
                $highest = $value;
            }
        }

        $candidates = [];
        foreach ($state->moodsOwnedBy($playerId) as $mood) {
            if ($state->valueOf($mood->cardId) === $highest) {
                $candidates[] = $mood->cardId;
            }
        }

        return $candidates;
    }

    public static function handCount(BoardState $state, int $playerId): int
    {
        return count($state->hand($playerId));
    }

    /** Whether $playerId holds strictly fewer ($fewer true) or more cards than EVERY other active player. */
    public static function handSizeBeatsEachOther(BoardState $state, int $playerId, bool $fewer): bool
    {
        $others = self::otherPlayerIds($state, $playerId);
        if ($others === []) {
            return false;
        }

        $mine = self::handCount($state, $playerId);
        foreach ($others as $otherId) {
            $theirs = self::handCount($state, $otherId);
            if ($fewer ? $mine >= $theirs : $mine <= $theirs) {
                return false;
            }
        }

        return true;
    }

    /**
     * Whether $playerId has won strictly fewer rounds than EVERY other active player (Resolve,
     * Perseverance). A teammate shares the team's count in a team format, so it can never be "fewer"
     * than them there -- the condition is simply false, as the text ("each other player") reads.
     */
    public static function hasWonFewerRoundsThanEachOther(BoardState $state, int $playerId): bool
    {
        $others = self::otherPlayerIds($state, $playerId);
        if ($others === []) {
            return false;
        }

        $mine = $state->roundWinsOf($playerId);
        foreach ($others as $otherId) {
            if ($mine >= $state->roundWinsOf($otherId)) {
                return false;
            }
        }

        return true;
    }

    /** @return string 'odd' or 'even' -- anything else is an invalid choice */
    public static function requireParity(string $parity): string
    {
        if (!in_array($parity, ['odd', 'even'], true)) {
            throw new \MoodSwings\Rules\Exceptions\InvalidChoiceException("'{$parity}' is not odd or even");
        }

        return $parity;
    }

    /** Whether $cardId's printed number (top right corner; 0 is even) is odd/even -- works for a card anywhere, not just in play. */
    public static function hasParity(BoardState $state, int $cardId, string $parity): bool
    {
        $number = $state->isInPlay($cardId) ? self::printedNumber($state, $cardId) : $state->catalogRow($cardId)['baseValue'];

        return ($number % 2 === 1) === ($parity === 'odd');
    }

    /**
     * A decision for $chooserId to pick one of $candidateIds -- cards the table can all see because they were
     * just revealed (an opponent's hand, the top of the deck) but that sit in no zone the client lists. The
     * 'revealed_card' field type carries the exact candidates; GameService::serializePendingDecision() turns
     * them into labelled options.
     *
     * @param int[] $candidateIds
     */
    public static function revealedCardRequest(string $key, int $chooserId, array $candidateIds, string $label): PendingDecisionRequest
    {
        return new PendingDecisionRequest(
            key: $key,
            targetPlayerId: $chooserId,
            decisionType: 'revealed_card_pick',
            field: ['key' => $key, 'type' => 'revealed_card', 'required' => true, 'label' => $label, 'candidate_card_ids' => array_values($candidateIds)],
        );
    }

    /** A mood's printed number (the top right corner): the card's base value, through any Creativity copy. */
    public static function printedNumber(BoardState $state, int $cardId): int
    {
        return $state->catalogRow($state->effectiveCardId($cardId))['baseValue'];
    }
}
