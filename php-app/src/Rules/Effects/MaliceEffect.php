<?php

declare(strict_types=1);

namespace MoodSwings\Rules\Effects;

use MoodSwings\Rules\AbstractMoodEffect;
use MoodSwings\Rules\BoardState;
use MoodSwings\Rules\Exceptions\InvalidChoiceException;
use MoodSwings\Rules\PlayerChoices;

/**
 * Malice: "After playing this mood, choose any player who has two or
 * more moods. That player chooses two of their moods. Put those moods,
 * and all other moods that share a color with either of them, into the
 * discard pile." Which two moods the target picks isn't specified as a
 * meaningful informed choice (their moods are already public information),
 * so -- consistent with Cruelty/Indecisiveness/Paranoia/Suspicion -- this
 * resolves with two random moods rather than needing the target's input.
 */
final class MaliceEffect extends AbstractMoodEffect
{
    private const MINIMUM_MOODS = 2;
    private const CHOSEN_COUNT = 2;

    public function afterPlaying(BoardState $state, int $cardId, int $playerId, PlayerChoices $choices): void
    {
        $targetPlayerId = $choices->int('target_player_id');
        if ($targetPlayerId === null) {
            return;
        }

        $moods = $state->moodsOwnedBy($targetPlayerId);
        if (count($moods) < self::MINIMUM_MOODS) {
            throw new InvalidChoiceException("Player {$targetPlayerId} does not have two or more moods");
        }

        $chosenCardIds = (array) array_rand($moods, self::CHOSEN_COUNT);
        $chosenColors = array_map(static fn (int $cid) => $state->colorOf($cid), $chosenCardIds);

        $targets = [];
        foreach ($state->moodsInPlay() as $mood) {
            if (in_array($mood->cardId, $chosenCardIds, true) || in_array($state->colorOf($mood->cardId), $chosenColors, true)) {
                $targets[] = $mood->cardId;
            }
        }

        foreach ($targets as $targetCardId) {
            $state->moveInPlayToDiscard($targetCardId);
        }
    }
}
