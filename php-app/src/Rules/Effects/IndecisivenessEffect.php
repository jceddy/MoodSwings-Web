<?php

declare(strict_types=1);

namespace MoodSwings\Rules\Effects;

use MoodSwings\Rules\AbstractMoodEffect;
use MoodSwings\Rules\BoardState;
use MoodSwings\Rules\Exceptions\InvalidChoiceException;
use MoodSwings\Rules\PlayerChoices;

/**
 * Indecisiveness: "After playing this mood, choose any number of
 * opponents who each have two or more moods. Each chosen player puts a
 * random one of their moods into their hand." Same shape as Cruelty, but
 * returning the mood to its owner's hand instead of discarding it --
 * including the same teammate exclusion in Open Team Play (see
 * BoardState::isTeammate()).
 */
final class IndecisivenessEffect extends AbstractMoodEffect
{
    private const MINIMUM_MOODS = 2;

    public function afterPlaying(BoardState $state, int $cardId, int $playerId, PlayerChoices $choices): void
    {
        $chosenPlayers = array_unique($choices->ints('opponent_player_ids'));

        foreach ($chosenPlayers as $opponentId) {
            if (!in_array($opponentId, $state->activePlayerOrder(), true)) {
                throw new InvalidChoiceException("Player {$opponentId} is not a valid player");
            }
            if ($opponentId === $playerId || $state->isTeammate($playerId, $opponentId)) {
                throw new InvalidChoiceException('Indecisiveness can only target opponents');
            }
            if (count($state->moodsOwnedBy($opponentId)) < self::MINIMUM_MOODS) {
                throw new InvalidChoiceException("Player {$opponentId} does not have two or more moods");
            }
        }

        foreach ($chosenPlayers as $opponentId) {
            $moods = $state->moodsOwnedBy($opponentId);
            $randomCardId = array_rand($moods);
            $state->moveInPlayToHand($randomCardId);
        }
    }
}
