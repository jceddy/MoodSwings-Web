<?php

declare(strict_types=1);

namespace MoodSwings\Rules;

/**
 * Default, no-op implementations of ChaosMoodEffect's own two hooks --
 * most chaos effects only have one or the other (chaos_effects.shape is
 * either 'after_playing' or 'while_in_play', never both), so a concrete
 * implementation extends this and overrides only the one it needs.
 */
abstract class AbstractChaosMoodEffect implements ChaosMoodEffect
{
    public function computeValue(BoardState $state, int $cardId, int $incomingValue): int
    {
        return $incomingValue;
    }

    public function afterPlaying(BoardState $state, int $cardId, int $playerId, PlayerChoices $choices): void
    {
    }

    public function perpetualTurnStartGrants(BoardState $state, int $cardId, int $ownerId): array
    {
        return [];
    }

    public function roundStartHook(BoardState $state, int $cardId, int $ownerId): void
    {
    }

    public function onMoodPlayed(BoardState $state, int $cardId, int $ownerId, int $playedByPlayerId, int $playedCardId): void
    {
    }

    public function onMoodDiscarded(BoardState $state, int $cardId, int $ownerId, int $discardedCardId, int $discardedOwnerId, int $discardedValue): void
    {
    }

    public function onMoodSuppressed(BoardState $state, int $cardId, int $ownerId, int $suppressedCardId): void
    {
    }

    public function scoringBonus(BoardState $state, int $cardId, int $ownerId): int
    {
        return 0;
    }

    public function afterScoring(BoardState $state, int $cardId, int $ownerId, array $scores, array $winningGamePlayerIds, int $lowestScorePlayerId): void
    {
    }
}
