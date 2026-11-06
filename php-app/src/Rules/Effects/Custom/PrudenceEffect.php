<?php

declare(strict_types=1);

namespace MoodSwings\Rules\Effects\Custom;

use MoodSwings\Rules\AbstractMoodEffect;
use MoodSwings\Rules\BoardState;
use MoodSwings\Rules\Exceptions\InvalidChoiceException;
use MoodSwings\Rules\PendingDecisionRequest;
use MoodSwings\Rules\PlayerChoices;
use MoodSwings\Rules\RequiresOpponentDecision;

/**
 * Prudence: "After playing this mood -- Look at the top two cards of the deck. Put any number of them on the bottom of the deck
 * and the rest back on top in any order." A private look: the two cards are shown only to the player in a pending decision (never
 * recorded as a public reveal -- the field's options are only sent to its target). First they pick which to send to the bottom
 * (none, one or both); if both stay, a second decision picks which goes on top (the order is at most two cards, so one pick says
 * it all). A deck with fewer than two cards shows what there is; an empty one does nothing. In a duel it is the owner's own deck.
 */
final class PrudenceEffect extends AbstractMoodEffect implements RequiresOpponentDecision
{
    private const LOOKED_AT = 2;
    private const BOTTOM = 'bottom_card_ids';
    private const TOP = 'top_card_id';

    public function pendingDecisionsFor(BoardState $state, int $cardId, int $playerId, PlayerChoices $choices): array
    {
        $top = array_slice($state->deck($playerId), 0, self::LOOKED_AT);
        if ($top === []) {
            return [];
        }

        return [new PendingDecisionRequest(
            key: self::BOTTOM,
            targetPlayerId: $playerId,
            decisionType: 'prudence_bottom',
            field: [
                'key' => self::BOTTOM,
                'type' => 'revealed_card',
                'multi' => true,
                'required' => false,
                'count' => ['max' => count($top)],
                'candidate_card_ids' => $top,
                'label' => 'Prudence: you look at the top of the deck. Choose any of these to put on the bottom of the deck (the rest go back on top)',
            ],
        )];
    }

    public function resolveDecisions(BoardState $state, int $cardId, int $playerId, PlayerChoices $choices, array $answers): array
    {
        $top = array_slice($state->deck($playerId), 0, self::LOOKED_AT);

        if (isset($answers[self::TOP])) {
            $chosenTopId = $answers[self::TOP]->requireInt(self::TOP);
            if (!in_array($chosenTopId, $top, true)) {
                throw new InvalidChoiceException("Card {$chosenTopId} is not one of the cards left on top");
            }
            if ($top[0] !== $chosenTopId) {
                $state->moveDeckCardToTop($playerId, $chosenTopId);
            }

            return [];
        }

        if (!isset($answers[self::BOTTOM])) {
            return [];
        }

        $bottomIds = array_values(array_unique($answers[self::BOTTOM]->ints(self::BOTTOM)));
        foreach ($bottomIds as $bottomId) {
            if (!in_array($bottomId, $top, true)) {
                throw new InvalidChoiceException("Card {$bottomId} is not one of the top cards you looked at");
            }
        }
        foreach ($bottomIds as $bottomId) {
            $state->moveDeckCardToBottom($playerId, $bottomId);
        }

        $kept = array_values(array_diff($top, $bottomIds));
        if (count($kept) < self::LOOKED_AT) {
            return [];
        }

        return [new PendingDecisionRequest(
            key: self::TOP,
            targetPlayerId: $playerId,
            decisionType: 'prudence_top',
            field: [
                'key' => self::TOP,
                'type' => 'revealed_card',
                'required' => true,
                'candidate_card_ids' => $kept,
                'label' => 'Prudence: choose which of these goes on top of the deck (the other goes second)',
            ],
        )];
    }
}
