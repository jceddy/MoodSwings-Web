<?php

declare(strict_types=1);

namespace MoodSwings\Rules\Effects\Custom;

use MoodSwings\Rules\BoardState;
use MoodSwings\Rules\Exceptions\InvalidChoiceException;
use MoodSwings\Rules\PendingDecisionRequest;
use MoodSwings\Rules\PlayerChoices;

/** Request builders shared by the community set's "at the start of each of your turns" moods. */
final class TurnStartSupport
{
    /**
     * A decision for $targetPlayerId to pick one of THEIR OWN moods. $required: a mandatory pick (the
     * trigger makes that player lose a mood), false for an optional "you may". Callers only ask a
     * player who owns at least one mood.
     */
    public static function ownMoodRequest(BoardState $state, string $key, int $targetPlayerId, bool $required, string $label): PendingDecisionRequest
    {
        $candidates = array_values(array_map(static fn ($mood) => $mood->cardId, $state->moodsOwnedBy($targetPlayerId)));

        return new PendingDecisionRequest(
            key: $key,
            targetPlayerId: $targetPlayerId,
            decisionType: 'turn_start_trigger',
            field: ['key' => $key, 'type' => 'mood', 'scope' => 'own', 'required' => $required, 'label' => $label, 'candidate_card_ids' => $candidates],
        );
    }

    /**
     * Puts every answered "<prefix><playerId>" mood into the discard pile, checking each really is one of
     * that player's own moods in play.
     *
     * @param array<string, PlayerChoices> $answers
     */
    public static function discardChosenOwnMoods(BoardState $state, array $answers, string $keyPrefix): void
    {
        foreach ($answers as $key => $answer) {
            if (!str_starts_with($key, $keyPrefix)) {
                continue;
            }
            $ownerId = (int) substr($key, strlen($keyPrefix));
            $moodId = $answer->requireInt($key);
            if (!$state->isInPlay($moodId) || $state->ownerOf($moodId) !== $ownerId) {
                throw new InvalidChoiceException("Card {$moodId} is not one of player {$ownerId}'s moods");
            }
            $state->moveInPlayToDiscard($moodId);
        }
    }
}
