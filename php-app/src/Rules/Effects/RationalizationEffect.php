<?php

declare(strict_types=1);

namespace MoodSwings\Rules\Effects;

use MoodSwings\Rules\AbstractMoodEffect;
use MoodSwings\Rules\BoardState;
use MoodSwings\Rules\Exceptions\InvalidChoiceException;
use MoodSwings\Rules\PlayerChoices;

/**
 * Rationalization: "After playing this mood, you may choose one: put
 * your hand on the bottom of the deck then draw that many cards, or
 * choose left or right and have each player simultaneously give their
 * hand to the next player in that direction." The whole thing is
 * optional ("you may choose one") -- declining (no 'mode' submitted) is
 * a no-op. The 'rotate' mode reuses Avoidance/Confusion's
 * direction-to-neighbor mapping, but swaps whole hands (snapshotted
 * before any transfers, so a hand received from one neighbor never gets
 * forwarded again in the same resolution) instead of one random card
 * each.
 */
final class RationalizationEffect extends AbstractMoodEffect
{
    public function afterPlaying(BoardState $state, int $cardId, int $playerId, PlayerChoices $choices): void
    {
        $mode = $choices->string('mode');
        if ($mode === null) {
            return;
        }

        match ($mode) {
            'refresh' => $this->refreshHand($state, $playerId),
            'rotate' => $this->rotateHands($state, $choices),
            default => throw new InvalidChoiceException("Rationalization's mode must be 'refresh' or 'rotate'"),
        };
    }

    private function refreshHand(BoardState $state, int $playerId): void
    {
        $hand = $state->hand($playerId);
        foreach ($hand as $handCardId) {
            $state->moveHandToBottomOfDeck($playerId, $handCardId);
        }
        foreach ($hand as $ignored) {
            $state->drawCard($playerId);
        }
    }

    private function rotateHands(BoardState $state, PlayerChoices $choices): void
    {
        $direction = $choices->requireString('direction');
        if (!in_array($direction, ['left', 'right'], true)) {
            throw new InvalidChoiceException("Rationalization's direction must be 'left' or 'right'");
        }

        $order = $state->playerOrder();
        $count = count($order);

        $hands = [];
        foreach ($order as $giverId) {
            $hands[$giverId] = $state->hand($giverId);
        }

        foreach ($order as $index => $giverId) {
            $neighborIndex = $direction === 'right' ? ($index + 1) % $count : ($index - 1 + $count) % $count;
            $recipientId = $order[$neighborIndex];
            foreach ($hands[$giverId] as $handCardId) {
                $state->giveHandCardToPlayer($giverId, $recipientId, $handCardId);
            }
        }
    }
}
