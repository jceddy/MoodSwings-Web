<?php

declare(strict_types=1);

namespace MoodSwings\Rules\ChaosEffects;

use MoodSwings\Rules\AbstractChaosMoodEffect;
use MoodSwings\Rules\BoardState;
use MoodSwings\Rules\ChaosRequiresOpponentDecision;
use MoodSwings\Rules\Exceptions\InvalidChoiceException;
use MoodSwings\Rules\PendingDecisionRequest;
use MoodSwings\Rules\PlayerChoices;

/**
 * chaos_010 (rare, after_playing): "Starting with the next player in turn
 * order, each player may choose a color. Put each other mood that shares
 * one of those colors into the discard pile." Identical printed text to
 * Effects/DisillusionmentEffect.php -- each player's own color pick is a
 * genuine decision (see ChaosRequiresOpponentDecision), per the card's own
 * "may" optional (`required: false`): a player who declines contributes no
 * color at all rather than being forced to pick one -- a maintainer ruling
 * reversing this class's own original design (issue #405 follow-up,
 * reported live for chaos_086's identically-shaped case: "the other
 * player should choose the card from their hand to give to me -- it
 * should not be random"). The queue starts with the next player after the
 * acting player and wraps around to end with the acting player themselves,
 * matching "starting with the next player in turn order" while asking
 * every player at the table (the acting player included). "Each other
 * mood" excludes only this card itself, regardless of owner.
 */
final class Chaos010Effect extends AbstractChaosMoodEffect implements ChaosRequiresOpponentDecision
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
                decisionType: 'chaos_010_choose_color',
                field: [
                    'key' => $key,
                    'type' => 'mode',
                    'options' => self::COLORS,
                    'required' => false,
                    'label' => 'Choose a color -- every other mood of that color is moved from play to discard',
                ],
            );
        }

        return $requests;
    }

    public function resolveDecisions(BoardState $state, int $cardId, int $playerId, PlayerChoices $choices, array $answers): array
    {
        $chosenColors = [];
        foreach ($this->queueOrder($state, $playerId) as $chosenPlayerId) {
            $key = self::KEY_PREFIX . $chosenPlayerId;
            $color = ($answers[$key] ?? null)?->string($key);
            if ($color === null) {
                continue;
            }
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

        return [];
    }

    /** @return int[] every player at the table, starting after $playerId and wrapping to end with $playerId */
    private function queueOrder(BoardState $state, int $playerId): array
    {
        $order = $state->activePlayerOrder();
        $index = array_search($playerId, $order, true);

        return array_merge(array_slice($order, $index + 1), array_slice($order, 0, $index + 1));
    }
}
