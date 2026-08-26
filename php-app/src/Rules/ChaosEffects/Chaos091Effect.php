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
 * chaos_091 (uncommon, after_playing): "Each player chooses one of their
 * highest value moods and puts it into the discard pile." Identical
 * printed text to Effects/FuryEffect.php -- the text says each player
 * "chooses", not "at random" (contrast the printed Paranoia/Cruelty/
 * Indecisiveness family and chaos_043/061, which genuinely are random per
 * their own printed text), so every player with at least one mood in play
 * gets their own queued decision, offered only the mood(s) tied for THEIR
 * OWN highest value (see ChaosRequiresOpponentDecision) -- a maintainer
 * ruling reversing this class's own original design (issue #405
 * follow-up, reported live for chaos_086's identically-shaped case: "the
 * other player should choose the card from their hand to give to me -- it
 * should not be random"). Nothing is discarded until every answer is in,
 * matching the printed text's simultaneous "each player" resolution --
 * nobody's own highest-value set can be affected by another player's
 * discard from this same resolution, since none of them happen until all
 * of them do.
 */
final class Chaos091Effect extends AbstractChaosMoodEffect implements ChaosRequiresOpponentDecision
{
    private const KEY_PREFIX = 'discarded_mood_id_';

    public function pendingDecisionsFor(BoardState $state, int $cardId, int $playerId, PlayerChoices $choices): array
    {
        $requests = [];
        foreach ($state->activePlayerOrder() as $ownerId) {
            $candidates = $this->highestValueMoodIds($state, $ownerId);
            if ($candidates === []) {
                continue;
            }

            $requests[] = new PendingDecisionRequest(
                key: self::KEY_PREFIX . $ownerId,
                targetPlayerId: $ownerId,
                decisionType: 'chaos_091_discard_mood',
                field: [
                    'key' => self::KEY_PREFIX . $ownerId,
                    'type' => 'mood',
                    'candidate_card_ids' => $candidates,
                    'required' => true,
                    'label' => 'Choose one of your highest value moods to put in the discard pile',
                ],
            );
        }

        return $requests;
    }

    public function resolveDecisions(BoardState $state, int $cardId, int $playerId, PlayerChoices $choices, array $answers): array
    {
        $targets = [];
        foreach ($state->activePlayerOrder() as $ownerId) {
            $key = self::KEY_PREFIX . $ownerId;
            if (!isset($answers[$key])) {
                continue;
            }

            $candidates = $this->highestValueMoodIds($state, $ownerId);
            $discardedCardId = $answers[$key]->requireInt($key);
            if (!in_array($discardedCardId, $candidates, true)) {
                throw new InvalidChoiceException("Card {$discardedCardId} is not one of player {$ownerId}'s highest value moods");
            }

            $targets[] = $discardedCardId;
        }

        foreach ($targets as $targetCardId) {
            $state->moveInPlayToDiscard($targetCardId);
        }

        return [];
    }

    /** @return int[] */
    private function highestValueMoodIds(BoardState $state, int $ownerId): array
    {
        $highestValue = -1;
        foreach ($state->moodsOwnedBy($ownerId) as $mood) {
            $value = $state->valueOf($mood->cardId);
            if ($value > $highestValue) {
                $highestValue = $value;
            }
        }

        $candidates = [];
        foreach ($state->moodsOwnedBy($ownerId) as $mood) {
            if ($state->valueOf($mood->cardId) === $highestValue) {
                $candidates[] = $mood->cardId;
            }
        }

        return $candidates;
    }
}
