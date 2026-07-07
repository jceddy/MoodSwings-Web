<?php

declare(strict_types=1);

namespace MoodSwings\Rules\Effects;

use MoodSwings\Rules\AbstractMoodEffect;
use MoodSwings\Rules\BoardState;
use MoodSwings\Rules\Exceptions\InvalidChoiceException;
use MoodSwings\Rules\PlayerChoices;

/**
 * Avoidance: "After playing this mood, choose left or right. Each player
 * chooses one of their moods and gives it to the next player in the
 * chosen direction." The acting player picks the direction; which
 * specific mood each player gives up isn't a meaningful informed choice
 * (moods in play are already public information), so -- consistent with
 * Cruelty/Malice/etc. -- each player's contribution is a random one of
 * their own moods. 'right' is defined as moving forward through seat
 * order (wrapping), 'left' as backward; every giver's mood is picked from
 * a single snapshot of the board before any transfers happen, so one
 * player's mood can't be re-given later in the same resolution.
 */
final class AvoidanceEffect extends AbstractMoodEffect
{
    public function afterPlaying(BoardState $state, int $cardId, int $playerId, PlayerChoices $choices): void
    {
        $direction = $choices->requireString('direction');
        if (!in_array($direction, ['left', 'right'], true)) {
            throw new InvalidChoiceException("Avoidance's direction must be 'left' or 'right'");
        }

        $order = $state->playerOrder();
        $count = count($order);

        $transfers = [];
        foreach ($order as $index => $giverId) {
            $moods = $state->moodsOwnedBy($giverId);
            if ($moods === []) {
                continue;
            }
            $randomCardId = array_rand($moods);
            $neighborIndex = $direction === 'right' ? ($index + 1) % $count : ($index - 1 + $count) % $count;
            $transfers[$randomCardId] = $order[$neighborIndex];
        }

        foreach ($transfers as $moodCardId => $recipientId) {
            $state->giveInPlayToPlayer($moodCardId, $recipientId);
        }
    }
}
