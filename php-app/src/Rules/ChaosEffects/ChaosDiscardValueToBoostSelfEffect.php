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
 * chaos_008/087/110/111: "After playing this mood, you may discard a card
 * from your hand with a <values> in its top right corner. If you do, this
 * mood's value becomes <boostedValue>." $qualifyingValues checks the
 * discarded hand card's own PRINTED base value (the number in its top
 * right corner), never its live/computed value, since a hand card has no
 * "in play" value to compute. Setting a value override on $cardId (rather
 * than returning a fixed value from computeValue()) is correct here since
 * this effect's shape is 'after_playing', not 'while_in_play' -- there's
 * no computeValue() hook for it to participate in at all. Uses
 * BoardState::setChaosValueOverride() rather than the base-card
 * setValueOverride() precisely because $cardId here is an essentially
 * arbitrary attached-to hand card, not this effect's own printed ability
 * -- see setChaosValueOverride()'s own docblock for why that distinction
 * matters (it keeps the frontend from rotating the card 180 degrees as if
 * its own printed ability had locked in a value).
 *
 * Deferred as a self-targeted `ChaosRequiresOpponentDecision` (issue #405
 * follow-up -- a bug caught live: attaching chaos_058, an identically-
 * shaped "discard/give a hand card" chaos effect, to Rationalization and
 * choosing its own "rotate hands" mode threw "Card is not in your hand"
 * -- Rationalization's own afterPlaying() had already replaced the
 * acting player's ENTIRE hand by the time this class's own
 * $choices->int('discard_card_id') check ran, since that value came from
 * the SAME up-front request as Rationalization's own choices, submitted
 * before either effect actually resolved). Every chaos effect reading a
 * hand card from the ACTING PLAYER'S OWN hand shares this same latent
 * staleness risk whenever attached to a card whose own printed effect
 * touches that hand first -- mirrors Betrayal's own reasoning for why a
 * same-player decision still needs a real, durable pause: "one of your
 * moods" can't be offered as an ordinary up-front field when the mood in
 * question won't exist (or, here, the hand won't look the same) until
 * after this invocation's own resolution has actually progressed.
 * pendingDecisionsFor() below always asks (mirroring Betrayal's own
 * unconditional pause) rather than gating on any up-front field, since
 * the field itself -- not some earlier "would you like to?" choice -- is
 * this effect's own entire "you may".
 */
final class ChaosDiscardValueToBoostSelfEffect extends AbstractChaosMoodEffect implements ChaosRequiresOpponentDecision
{
    private const KEY = 'discard_card_id';

    /** @param int[] $qualifyingValues */
    public function __construct(
        private readonly array $qualifyingValues,
        private readonly int $boostedValue,
    ) {
    }

    public function pendingDecisionsFor(BoardState $state, int $cardId, int $playerId, PlayerChoices $choices): array
    {
        return [
            new PendingDecisionRequest(
                key: self::KEY,
                targetPlayerId: $playerId,
                decisionType: 'chaos_discard_value_boost',
                field: [
                    'key' => self::KEY,
                    'type' => 'hand_card',
                    'required' => false,
                    'filter' => ['values' => $this->qualifyingValues],
                    'label' => "Card to discard -- boosts this mood to {$this->boostedValue}",
                ],
            ),
        ];
    }

    public function resolveDecisions(BoardState $state, int $cardId, int $playerId, PlayerChoices $choices, array $answers): array
    {
        $discardCardId = ($answers[self::KEY] ?? null)?->int(self::KEY);
        if ($discardCardId === null) {
            return [];
        }

        if (!$state->isInHand($playerId, $discardCardId)) {
            throw new InvalidChoiceException("Card {$discardCardId} is not in your hand");
        }

        $baseValue = $state->catalogRow($state->catalogCardId($discardCardId))['baseValue'];
        if (!in_array($baseValue, $this->qualifyingValues, true)) {
            throw new InvalidChoiceException("Card {$discardCardId} does not have a qualifying value");
        }

        $state->moveHandToDiscard($playerId, $discardCardId);
        $state->setChaosValueOverride($cardId, $this->boostedValue);

        return [];
    }
}
