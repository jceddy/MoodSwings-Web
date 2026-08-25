<?php

declare(strict_types=1);

namespace MoodSwings\Rules\ChaosEffects;

use MoodSwings\Rules\AbstractChaosMoodEffect;
use MoodSwings\Rules\BoardState;
use MoodSwings\Rules\Exceptions\InvalidChoiceException;
use MoodSwings\Rules\PlayerChoices;

/**
 * chaos_031 (uncommon, after_playing): "Choose left or right. Each player
 * chooses a card from their hand and gives it to the next player in the
 * chosen direction." Same "randomize the other players' own choices"
 * simplification as Chaos029Effect; a player with an empty hand simply
 * has nothing to give.
 */
final class Chaos031Effect extends AbstractChaosMoodEffect
{
    public function afterPlaying(BoardState $state, int $cardId, int $playerId, PlayerChoices $choices): void
    {
        $direction = $choices->requireString('direction');
        if (!in_array($direction, ['left', 'right'], true)) {
            throw new InvalidChoiceException("'{$direction}' is not a valid direction");
        }

        $outgoing = [];
        foreach ($state->activePlayerOrder() as $ownerId) {
            $hand = $state->hand($ownerId);
            if ($hand === []) {
                continue;
            }
            $outgoing[$ownerId] = $hand[array_rand($hand)];
        }

        foreach ($outgoing as $ownerId => $movingCardId) {
            $recipientId = $state->activeNeighbor($ownerId, $direction);
            if ($recipientId !== null && $recipientId !== $ownerId) {
                $state->giveHandCardToPlayer($ownerId, $recipientId, $movingCardId);
            }
        }
    }
}
