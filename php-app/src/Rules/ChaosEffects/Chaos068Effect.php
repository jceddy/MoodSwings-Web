<?php

declare(strict_types=1);

namespace MoodSwings\Rules\ChaosEffects;

use MoodSwings\Rules\AbstractChaosMoodEffect;
use MoodSwings\Rules\BoardState;
use MoodSwings\Rules\Exceptions\InvalidChoiceException;
use MoodSwings\Rules\PlayerChoices;

/**
 * chaos_068 (mythic, after_playing): "Choose any player who has two or
 * more moods (moods are cards in play). That player chooses two of their
 * moods. Put those moods and all other moods that share a color with
 * either of them into the discard pile." The chosen player's own "chooses
 * two of their moods" is simplified to two uniformly-random ones of
 * theirs.
 */
final class Chaos068Effect extends AbstractChaosMoodEffect
{
    public function afterPlaying(BoardState $state, int $cardId, int $playerId, PlayerChoices $choices): void
    {
        $targetPlayerId = $choices->requireInt('target_player_id');
        if (!in_array($targetPlayerId, $state->activePlayerOrder(), true)) {
            throw new InvalidChoiceException("Player {$targetPlayerId} is not a valid player");
        }

        $moods = $state->moodsOwnedBy($targetPlayerId);
        if (count($moods) < 2) {
            throw new InvalidChoiceException("Player {$targetPlayerId} does not have two or more moods");
        }

        $cardIds = array_keys($moods);
        shuffle($cardIds);
        [$firstCardId, $secondCardId] = [$cardIds[0], $cardIds[1]];
        $colors = [$state->colorOf($firstCardId), $state->colorOf($secondCardId)];

        foreach ($state->moodsInPlay() as $mood) {
            if ($mood->cardId === $firstCardId || $mood->cardId === $secondCardId || in_array($state->colorOf($mood->cardId), $colors, true)) {
                $state->moveInPlayToDiscard($mood->cardId);
            }
        }
    }
}
