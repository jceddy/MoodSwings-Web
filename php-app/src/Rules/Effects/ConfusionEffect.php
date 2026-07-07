<?php

declare(strict_types=1);

namespace MoodSwings\Rules\Effects;

use MoodSwings\Rules\AbstractMoodEffect;
use MoodSwings\Rules\BoardState;
use MoodSwings\Rules\Exceptions\InvalidChoiceException;
use MoodSwings\Rules\PlayerChoices;

/**
 * Confusion: "After playing this mood, choose left or right. Each player
 * chooses a card from their hand and gives it to the next player in the
 * chosen direction." Same direction-based neighbor mechanic as Avoidance,
 * applied to hand cards instead of moods in play.
 */
final class ConfusionEffect extends AbstractMoodEffect
{
    public function afterPlaying(BoardState $state, int $cardId, int $playerId, PlayerChoices $choices): void
    {
        $direction = $choices->requireString('direction');
        if (!in_array($direction, ['left', 'right'], true)) {
            throw new InvalidChoiceException("Confusion's direction must be 'left' or 'right'");
        }

        $order = $state->playerOrder();
        $count = count($order);

        $transfers = [];
        foreach ($order as $index => $giverId) {
            $hand = $state->hand($giverId);
            if ($hand === []) {
                continue;
            }
            $randomCardId = $hand[array_rand($hand)];
            $neighborIndex = $direction === 'right' ? ($index + 1) % $count : ($index - 1 + $count) % $count;
            $transfers[] = [$giverId, $randomCardId, $order[$neighborIndex]];
        }

        foreach ($transfers as [$giverId, $handCardId, $recipientId]) {
            $state->giveHandCardToPlayer($giverId, $recipientId, $handCardId);
        }
    }
}
