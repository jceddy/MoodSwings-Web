<?php

declare(strict_types=1);

namespace MoodSwings\Rules\Effects\Custom;

use MoodSwings\Rules\BoardState;

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

    /** A mood's printed number (the top right corner): the card's base value, through any Creativity copy. */
    public static function printedNumber(BoardState $state, int $cardId): int
    {
        return $state->catalogRow($state->effectiveCardId($cardId))['baseValue'];
    }
}
