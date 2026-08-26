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
 * chaos_031 (uncommon, after_playing): "Choose left or right. Each player
 * chooses a card from their hand and gives it to the next player in the
 * chosen direction." Identical printed text to Effects/ConfusionEffect.php
 * -- same direction-based neighbor mechanic as chaos_029, applied to hand
 * cards instead of moods in play. The text says each player "chooses"
 * their own card, not "at random", so every player with a non-empty hand
 * gets their own queued decision (see ChaosRequiresOpponentDecision),
 * including the acting player themselves -- a maintainer ruling reversing
 * this class's own original design (issue #405 follow-up, reported live
 * for chaos_086's identically-shaped case: "the other player should
 * choose the card from their hand to give to me -- it should not be
 * random"). All transfers are computed against everyone's ORIGINAL hand
 * and only applied once every answer is in, matching the printed text's
 * simultaneous "each player" exchange -- nobody's choice is affected by a
 * card they're about to receive from this same resolution.
 */
final class Chaos031Effect extends AbstractChaosMoodEffect implements ChaosRequiresOpponentDecision
{
    private const KEY_PREFIX = 'given_card_id_';

    public function pendingDecisionsFor(BoardState $state, int $cardId, int $playerId, PlayerChoices $choices): array
    {
        $direction = $choices->requireString('direction');
        if (!in_array($direction, ['left', 'right'], true)) {
            throw new InvalidChoiceException("'{$direction}' is not a valid direction");
        }

        $requests = [];
        foreach ($state->activePlayerOrder() as $giverId) {
            if ($state->hand($giverId) === []) {
                continue;
            }

            $requests[] = new PendingDecisionRequest(
                key: self::KEY_PREFIX . $giverId,
                targetPlayerId: $giverId,
                decisionType: 'chaos_031_give_card',
                field: [
                    'key' => self::KEY_PREFIX . $giverId,
                    'type' => 'hand_card',
                    'required' => true,
                    'label' => "Choose a card from your hand to give to your {$direction}-hand neighbor",
                ],
            );
        }

        return $requests;
    }

    public function resolveDecisions(BoardState $state, int $cardId, int $playerId, PlayerChoices $choices, array $answers): array
    {
        $direction = $choices->requireString('direction');

        $transfers = [];
        foreach ($state->activePlayerOrder() as $giverId) {
            $key = self::KEY_PREFIX . $giverId;
            if (!isset($answers[$key])) {
                continue;
            }

            $givenCardId = $answers[$key]->requireInt($key);
            if (!$state->isInHand($giverId, $givenCardId)) {
                throw new InvalidChoiceException("Card {$givenCardId} is not in player {$giverId}'s hand");
            }

            $recipientId = $state->activeNeighbor($giverId, $direction);
            if ($recipientId === null) {
                continue;
            }
            $transfers[] = [$giverId, $givenCardId, $recipientId];
        }

        foreach ($transfers as [$giverId, $handCardId, $recipientId]) {
            $state->giveHandCardToPlayer($giverId, $recipientId, $handCardId);
        }

        return [];
    }
}
