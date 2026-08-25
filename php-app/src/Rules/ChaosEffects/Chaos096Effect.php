<?php

declare(strict_types=1);

namespace MoodSwings\Rules\ChaosEffects;

use MoodSwings\Rules\AbstractChaosMoodEffect;
use MoodSwings\Rules\BoardState;
use MoodSwings\Rules\Exceptions\InvalidChoiceException;
use MoodSwings\Rules\PlayerChoices;

/**
 * chaos_096 (rare, after_playing): "You may choose two moods from the
 * same opponent. If you do, they choose one of those moods and gives it
 * to you, then you give them one of your moods." The opponent's own
 * choice between the two named moods is simplified to a uniformly-random
 * one of them (see ChaosMoodEffect's own docblock); which of the active
 * player's own moods goes the other way is their own genuine choice.
 */
final class Chaos096Effect extends AbstractChaosMoodEffect
{
    public function afterPlaying(BoardState $state, int $cardId, int $playerId, PlayerChoices $choices): void
    {
        $targetCardIds = array_unique($choices->ints('opponent_mood_card_ids'));
        if ($targetCardIds === []) {
            return;
        }
        if (count($targetCardIds) !== 2) {
            throw new InvalidChoiceException('Choose exactly two moods from the same opponent');
        }

        [$firstCardId, $secondCardId] = array_values($targetCardIds);
        if (!$state->isInPlay($firstCardId) || !$state->isInPlay($secondCardId)) {
            throw new InvalidChoiceException('Both chosen moods must be in play');
        }
        $opponentId = $state->ownerOf($firstCardId);
        if ($state->ownerOf($secondCardId) !== $opponentId) {
            throw new InvalidChoiceException('Both chosen moods must belong to the same opponent');
        }
        if ($opponentId === $playerId || $state->isTeammate($playerId, $opponentId)) {
            throw new InvalidChoiceException('Only an opponent\'s moods can be chosen');
        }

        $givenBackCardId = $choices->requireInt('own_mood_card_id');
        if ($givenBackCardId === $cardId || !in_array($givenBackCardId, array_map(static fn ($mood) => $mood->cardId, $state->moodsOwnedBy($playerId)), true)) {
            throw new InvalidChoiceException("Card {$givenBackCardId} is not one of your moods");
        }

        $takenCardId = [$firstCardId, $secondCardId][array_rand([$firstCardId, $secondCardId])];
        $state->giveInPlayToPlayer($takenCardId, $playerId);
        $state->giveInPlayToPlayer($givenBackCardId, $opponentId);
    }
}
