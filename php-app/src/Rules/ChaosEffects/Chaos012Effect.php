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
 * chaos_012 (uncommon, after_playing): "You may discard a green or blue
 * card from your hand. If you do, suppress any mood. It remains
 * suppressed for as long as you have this mood." Deferred as a
 * self-targeted `ChaosRequiresOpponentDecision` -- see
 * ChaosDiscardValueToBoostSelfEffect's own docblock for the full "why"
 * (issue #405 follow-up: a hand card chosen from the same up-front
 * request that plays the host card can go stale if the host card's own
 * printed effect changes the acting player's hand first, e.g.
 * Rationalization's own "rotate hands" mode). Both fields are bundled
 * into ONE nested pending decision (mirroring MoodPlayService::
 * duplicityRepeatOfferRequest()'s own nested shape) since which mood to
 * suppress doesn't depend on which hand card was discarded -- there's no
 * reason to force two separate round trips.
 */
final class Chaos012Effect extends AbstractChaosMoodEffect implements ChaosRequiresOpponentDecision
{
    private const KEY = 'discard_and_suppress';

    public function pendingDecisionsFor(BoardState $state, int $cardId, int $playerId, PlayerChoices $choices): array
    {
        return [
            new PendingDecisionRequest(
                key: self::KEY,
                targetPlayerId: $playerId,
                decisionType: 'chaos_012_discard_and_suppress',
                field: [
                    'key' => self::KEY,
                    'type' => 'nested',
                    'required' => false,
                    'label' => 'You may discard a green or blue card to suppress a mood',
                    'fields' => [
                        ['key' => 'discard_card_id', 'type' => 'hand_card', 'required' => false, 'filter' => ['colors' => ['green', 'blue']], 'label' => 'Card to discard (green or blue)'],
                        ['key' => 'suppress_mood_card_id', 'type' => 'mood', 'required' => false, 'scope' => 'any', 'label' => 'Mood to suppress (required if discarding a card above)'],
                    ],
                ],
            ),
        ];
    }

    public function resolveDecisions(BoardState $state, int $cardId, int $playerId, PlayerChoices $choices, array $answers): array
    {
        $answer = ($answers[self::KEY] ?? null)?->sub(self::KEY);
        $discardCardId = $answer?->int('discard_card_id');
        if ($discardCardId === null) {
            return [];
        }

        if (!$state->isInHand($playerId, $discardCardId)) {
            throw new InvalidChoiceException("Card {$discardCardId} is not in your hand");
        }
        $color = $state->colorOf($discardCardId);
        if (!in_array($color, ['green', 'blue'], true)) {
            throw new InvalidChoiceException("Card {$discardCardId} is not green or blue");
        }

        $targetCardId = $answer->requireInt('suppress_mood_card_id');
        if (!$state->isInPlay($targetCardId)) {
            throw new InvalidChoiceException("Card {$targetCardId} is not in play");
        }

        $state->moveHandToDiscard($playerId, $discardCardId);
        $state->suppress($targetCardId, 'while_source_in_play', $cardId);

        return [];
    }
}
