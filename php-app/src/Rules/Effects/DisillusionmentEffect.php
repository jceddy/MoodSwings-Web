<?php

declare(strict_types=1);

namespace MoodSwings\Rules\Effects;

use MoodSwings\Rules\AbstractMoodEffect;
use MoodSwings\Rules\BoardState;
use MoodSwings\Rules\Exceptions\InvalidChoiceException;
use MoodSwings\Rules\PendingDecisionRequest;
use MoodSwings\Rules\PlayerChoices;
use MoodSwings\Rules\RequiresOpponentDecision;

/**
 * Disillusionment: "After playing this mood, starting with the next
 * player in turn order, each player may choose a color. Put each other
 * mood that shares one of those colors into the discard pile." Each
 * player's own color pick is a genuine decision -- see
 * RequiresOpponentDecision. The queue starts with the next player after
 * the acting player and wraps around to end with the acting player
 * themselves, matching "starting with the next player in turn order"
 * while preserving the existing behavior of asking every player at the
 * table (the acting player included) -- this does not attempt to add a
 * "decline" option the original random implementation never modeled
 * either. "Each other mood" excludes only Disillusionment itself,
 * regardless of owner.
 */
final class DisillusionmentEffect extends AbstractMoodEffect implements RequiresOpponentDecision
{
    private const COLORS = ['white', 'blue', 'black', 'red', 'green'];
    private const KEY_PREFIX = 'chosen_color_';

    public function pendingDecisionsFor(BoardState $state, int $cardId, int $playerId, PlayerChoices $choices): array
    {
        $requests = [];
        foreach ($this->queueOrder($state, $playerId) as $chosenPlayerId) {
            $key = self::KEY_PREFIX . $chosenPlayerId;
            $requests[] = new PendingDecisionRequest(
                key: $key,
                targetPlayerId: $chosenPlayerId,
                decisionType: 'disillusionment_choose_color',
                field: [
                    'key' => $key,
                    'type' => 'mode',
                    'options' => self::COLORS,
                    'required' => true,
                    'label' => 'Choose a color -- every other mood of that color is discarded',
                ],
            );
        }

        return $requests;
    }

    public function resolveDecisions(BoardState $state, int $cardId, int $playerId, PlayerChoices $choices, array $answers): void
    {
        $chosenColors = [];
        foreach ($this->queueOrder($state, $playerId) as $chosenPlayerId) {
            $key = self::KEY_PREFIX . $chosenPlayerId;
            if (!isset($answers[$key])) {
                continue;
            }

            $color = $answers[$key]->requireString($key);
            if (!in_array($color, self::COLORS, true)) {
                throw new InvalidChoiceException("'{$color}' is not a valid color");
            }
            $chosenColors[] = $color;
        }
        $chosenColors = array_unique($chosenColors);

        foreach ($state->moodsInPlay() as $mood) {
            if ($mood->cardId === $cardId) {
                continue;
            }
            if (in_array($state->colorOf($mood->cardId), $chosenColors, true)) {
                $state->moveInPlayToDiscard($mood->cardId);
            }
        }
    }

    /** @return int[] every player at the table, starting after $playerId and wrapping to end with $playerId */
    private function queueOrder(BoardState $state, int $playerId): array
    {
        $order = $state->activePlayerOrder();
        $index = array_search($playerId, $order, true);

        return array_merge(array_slice($order, $index + 1), array_slice($order, 0, $index + 1));
    }
}
