<?php

declare(strict_types=1);

namespace MoodSwings\Rules\Effects;

use MoodSwings\Rules\AbstractMoodEffect;
use MoodSwings\Rules\BoardState;
use MoodSwings\Rules\Exceptions\InvalidChoiceException;
use MoodSwings\Rules\PlayerChoices;

/**
 * Generosity: "After playing this mood, choose an opponent. They may
 * play an additional mood on their next turn." Tags the chosen opponent
 * via the well-known 'banksExtraPlayForPlayerId' effectState key, which
 * GameService::computeFreshGrants() consults (and clears, since it's a
 * one-shot grant) the next time that specific player's turn starts --
 * however many turns from now that ends up being. You can't choose a
 * teammate in Open Team Play, since they aren't an opponent -- see
 * BoardState::isTeammate().
 */
final class GenerosityEffect extends AbstractMoodEffect
{
    public function afterPlaying(BoardState $state, int $cardId, int $playerId, PlayerChoices $choices): void
    {
        $opponentId = $choices->requireInt('target_player_id');
        if (!in_array($opponentId, $state->activePlayerOrder(), true)) {
            throw new InvalidChoiceException("Player {$opponentId} is not a valid player");
        }
        if ($opponentId === $playerId || $state->isTeammate($playerId, $opponentId)) {
            throw new InvalidChoiceException('Generosity must target an opponent');
        }

        $state->setEffectState($cardId, 'banksExtraPlayForPlayerId', $opponentId);
    }
}
