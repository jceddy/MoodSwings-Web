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
 * Fury: "After playing this mood, each player chooses one of their
 * highest value moods and puts it into the discard pile." The text says
 * each player "chooses" -- not "at random" (contrast Paranoia/Cruelty/
 * Indecisiveness, which do) -- so every player with at least one mood in
 * play gets their own queued decision (see RequiresOpponentDecision, and
 * Avoidance/Confusion's identical "every qualifying player, including the
 * acting player" shape), offered only the mood(s) tied for THEIR OWN
 * highest value (a single, trivial option when there's no tie; several
 * when there is). Nothing is discarded until every answer is in (see
 * resolveDecisions()), matching the printed text's simultaneous "each
 * player" resolution -- nobody's own highest-value set can be affected by
 * another player's discard from this same resolution, since none of them
 * happen until all of them do.
 */
final class FuryEffect extends AbstractMoodEffect implements RequiresOpponentDecision
{
    private const KEY_PREFIX = 'discarded_mood_id_';

    public function pendingDecisionsFor(BoardState $state, int $cardId, int $playerId, PlayerChoices $choices): array
    {
        $requests = [];
        foreach ($state->playerOrder() as $ownerId) {
            $candidates = $this->highestValueMoodIds($state, $ownerId);
            if ($candidates === []) {
                continue;
            }

            $requests[] = new PendingDecisionRequest(
                key: self::KEY_PREFIX . $ownerId,
                targetPlayerId: $ownerId,
                decisionType: 'fury_discard_mood',
                field: [
                    'key' => self::KEY_PREFIX . $ownerId,
                    'type' => 'mood',
                    'candidate_card_ids' => $candidates,
                    'required' => true,
                    'label' => 'Fury: choose one of your highest value moods to discard',
                ],
            );
        }

        return $requests;
    }

    public function resolveDecisions(BoardState $state, int $cardId, int $playerId, PlayerChoices $choices, array $answers): void
    {
        $targets = [];
        foreach ($state->playerOrder() as $ownerId) {
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
