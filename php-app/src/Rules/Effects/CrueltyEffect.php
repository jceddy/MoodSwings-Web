<?php

declare(strict_types=1);

namespace MoodSwings\Rules\Effects;

use MoodSwings\Rules\AbstractMoodEffect;
use MoodSwings\Rules\BoardState;
use MoodSwings\Rules\Exceptions\InvalidChoiceException;
use MoodSwings\Rules\PlayerChoices;

/**
 * Cruelty: "After playing this mood, choose any number of opponents who
 * each have two or more moods. Each chosen opponent puts a random one of
 * their moods into the discard pile." The rules specify a genuinely
 * random mood, unlike cards that let a targeted player choose -- so this
 * is fully resolvable here rather than needing another player's input.
 * You may not choose yourself (you're not your own opponent), and in
 * Open Team Play you may not choose a teammate either, for the same
 * reason -- see BoardState::isTeammate().
 */
final class CrueltyEffect extends AbstractMoodEffect
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
                throw new InvalidChoiceException('Cruelty can only target opponents');
            }
            if (count($state->moodsOwnedBy($opponentId)) < self::MINIMUM_MOODS) {
                throw new InvalidChoiceException("Player {$opponentId} does not have two or more moods");
            }
        }

        foreach ($chosenPlayers as $opponentId) {
            $moods = $state->moodsOwnedBy($opponentId);
            $randomCardId = array_rand($moods);
            $state->moveInPlayToDiscard($randomCardId);
        }
    }
}
