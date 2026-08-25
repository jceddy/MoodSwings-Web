<?php

declare(strict_types=1);

namespace MoodSwings\Rules\ChaosEffects;

use MoodSwings\Rules\AbstractChaosMoodEffect;
use MoodSwings\Rules\BoardState;
use MoodSwings\Rules\Exceptions\InvalidChoiceException;
use MoodSwings\Rules\PlayerChoices;

/** chaos_022 (rare, after_playing): "You may choose a player with more moods than you (moods are cards in play). If you do, you may keep playing additional moods this turn until you have as many moods as the chosen player." */
final class Chaos022Effect extends AbstractChaosMoodEffect
{
    public function afterPlaying(BoardState $state, int $cardId, int $playerId, PlayerChoices $choices): void
    {
        $targetPlayerId = $choices->int('target_player_id');
        if ($targetPlayerId === null) {
            return;
        }

        if (!in_array($targetPlayerId, $state->activePlayerOrder(), true)) {
            throw new InvalidChoiceException("Player {$targetPlayerId} is not a valid player");
        }

        $ownCount = count($state->moodsOwnedBy($playerId));
        $targetCount = count($state->moodsOwnedBy($targetPlayerId));
        if ($targetCount <= $ownCount) {
            throw new InvalidChoiceException("Player {$targetPlayerId} does not have more moods than you");
        }

        $state->grantExtraPlay($targetCount - $ownCount, sourceCardId: $cardId);
    }
}
