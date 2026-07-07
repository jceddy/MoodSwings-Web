<?php

declare(strict_types=1);

namespace MoodSwings\Rules\Effects;

use MoodSwings\Rules\AbstractMoodEffect;
use MoodSwings\Rules\BoardState;
use MoodSwings\Rules\Exceptions\InvalidChoiceException;
use MoodSwings\Rules\PlayerChoices;

/**
 * Hostility: "After playing this mood, you may put one of your black or
 * green moods into the discard pile. If you do, put up to two moods,
 * each with a value of 3 or less, into the discard pile." A two-stage
 * optional effect like Worry, but unlike Worry the second stage doesn't
 * exclude this mood itself from being a legal target -- Hostility's own
 * flat value (3) happens to qualify, and the card text has no "other
 * than this one" clause the way Worry's does.
 */
final class HostilityEffect extends AbstractMoodEffect
{
    private const QUALIFYING_COLORS = ['black', 'green'];
    private const MAX_SECONDARY_TARGETS = 2;
    private const MAXIMUM_VALUE = 3;

    public function afterPlaying(BoardState $state, int $cardId, int $playerId, PlayerChoices $choices): void
    {
        $ownMoodId = $choices->int('discard_mood_id');
        if ($ownMoodId === null) {
            return;
        }
        if (!$state->isInPlay($ownMoodId) || $state->ownerOf($ownMoodId) !== $playerId) {
            throw new InvalidChoiceException("Card {$ownMoodId} is not one of player {$playerId}'s moods in play");
        }
        if (!in_array($state->colorOf($ownMoodId), self::QUALIFYING_COLORS, true)) {
            throw new InvalidChoiceException('Hostility requires discarding a black or green mood');
        }

        $state->moveInPlayToDiscard($ownMoodId);

        $targets = array_unique($choices->ints('target_mood_ids'));
        if (count($targets) > self::MAX_SECONDARY_TARGETS) {
            throw new InvalidChoiceException('Hostility can only affect up to two additional moods');
        }

        foreach ($targets as $targetCardId) {
            if ($targetCardId === $ownMoodId) {
                throw new InvalidChoiceException("Card {$targetCardId} is not a valid target for Hostility");
            }
            if (!$state->isInPlay($targetCardId)) {
                throw new InvalidChoiceException("Card {$targetCardId} is not in play");
            }
            if ($state->valueOf($targetCardId) > self::MAXIMUM_VALUE) {
                throw new InvalidChoiceException("Card {$targetCardId} does not have a value of 3 or less");
            }
        }

        foreach ($targets as $targetCardId) {
            $state->moveInPlayToDiscard($targetCardId);
        }
    }
}
