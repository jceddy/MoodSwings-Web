<?php

declare(strict_types=1);

namespace MoodSwings\Rules\ChaosEffects;

use MoodSwings\Rules\AbstractChaosMoodEffect;
use MoodSwings\Rules\BoardState;
use MoodSwings\Rules\Exceptions\InvalidChoiceException;
use MoodSwings\Rules\PlayerChoices;

/** chaos_049 (rare, after_playing): "You may choose one: Put your hand on the bottom of the deck, then draw that many cards. -- Choose left or right. Simultaneously, each player gives their hand to the next player in the chosen direction." */
final class Chaos049Effect extends AbstractChaosMoodEffect
{
    public function afterPlaying(BoardState $state, int $cardId, int $playerId, PlayerChoices $choices): void
    {
        $mode = $choices->string('mode');
        if ($mode === null) {
            return;
        }

        if ($mode === 'redraw') {
            $hand = $state->hand($playerId);
            foreach ($hand as $handCardId) {
                $state->moveHandToBottomOfDeck($playerId, $handCardId);
            }
            foreach ($hand as $ignored) {
                $state->drawCard($playerId);
            }

            return;
        }

        if ($mode !== 'pass') {
            throw new InvalidChoiceException("'{$mode}' is not a valid mode");
        }

        $direction = $choices->requireString('direction');
        if (!in_array($direction, ['left', 'right'], true)) {
            throw new InvalidChoiceException("'{$direction}' is not a valid direction");
        }

        $handsByOwner = [];
        foreach ($state->activePlayerOrder() as $ownerId) {
            $handsByOwner[$ownerId] = $state->hand($ownerId);
        }

        foreach ($handsByOwner as $ownerId => $hand) {
            $recipientId = $state->activeNeighbor($ownerId, $direction);
            if ($recipientId === null || $recipientId === $ownerId) {
                continue;
            }
            foreach ($hand as $handCardId) {
                $state->giveHandCardToPlayer($ownerId, $recipientId, $handCardId);
            }
        }
    }
}
