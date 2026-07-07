<?php

declare(strict_types=1);

namespace MoodSwings\Rules\Effects;

use MoodSwings\Rules\AbstractMoodEffect;
use MoodSwings\Rules\BoardState;
use MoodSwings\Rules\PlayerChoices;

/** Grief: "After playing this mood, you may play up to two additional moods this turn from the discard pile." Same shape as Harmony, granting two discard-sourced plays instead of one. */
final class GriefEffect extends AbstractMoodEffect
{
    private const GRANTED_PLAYS = 2;

    public function afterPlaying(BoardState $state, int $cardId, int $playerId, PlayerChoices $choices): void
    {
        $state->grantExtraPlay(self::GRANTED_PLAYS, ['source' => 'discard']);
    }
}
