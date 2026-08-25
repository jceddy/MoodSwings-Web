<?php

declare(strict_types=1);

namespace MoodSwings\Rules\ChaosEffects;

use MoodSwings\Rules\AbstractChaosMoodEffect;
use MoodSwings\Rules\BoardState;
use MoodSwings\Rules\Exceptions\InvalidChoiceException;
use MoodSwings\Rules\PlayerChoices;

/**
 * chaos_043 (uncommon, after_playing): "Choose any number of opponents
 * who each have two or more moods. Each chosen player puts a random one
 * of their moods into their hand." Same opponent-only/random-mood shape
 * as Effects/CrueltyEffect.php, action = hand instead of discard.
 */
final class Chaos043Effect extends AbstractChaosMoodEffect
{
    private const MINIMUM_MOODS = 2;

    public function afterPlaying(BoardState $state, int $cardId, int $playerId, PlayerChoices $choices): void
    {
        $chosenPlayerIds = array_unique($choices->ints('opponent_player_ids'));

        foreach ($chosenPlayerIds as $opponentId) {
            if (!in_array($opponentId, $state->activePlayerOrder(), true)) {
                throw new InvalidChoiceException("Player {$opponentId} is not a valid player");
            }
            if ($opponentId === $playerId || $state->isTeammate($playerId, $opponentId)) {
                throw new InvalidChoiceException('Only opponents can be chosen');
            }
            if (count($state->moodsOwnedBy($opponentId)) < self::MINIMUM_MOODS) {
                throw new InvalidChoiceException("Player {$opponentId} does not have two or more moods");
            }
        }

        foreach ($chosenPlayerIds as $opponentId) {
            $moods = $state->moodsOwnedBy($opponentId);
            $randomCardId = array_rand($moods);
            $state->moveInPlayToHand($randomCardId);
        }
    }
}
