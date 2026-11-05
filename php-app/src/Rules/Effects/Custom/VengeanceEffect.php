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
 * Vengeance: "After playing this mood -- Choose an opponent. That player puts one of their highest value moods
 * into the discard pile." Fury's wording for the target restriction: the opponent chooses among their own ties,
 * so the acting player has no free pick of the best answer. With no opponent holding a mood the choice is
 * simply left out (and the play does nothing).
 */
final class VengeanceEffect extends AbstractMoodEffect implements RequiresOpponentDecision
{
    private const KEY = 'discarded_mood_id';

    public function pendingDecisionsFor(BoardState $state, int $cardId, int $playerId, PlayerChoices $choices): array
    {
        if (!$choices->has('target_player_id')) {
            return [];
        }

        $targetPlayerId = $choices->requireInt('target_player_id');
        if (!in_array($targetPlayerId, CustomEffectSupport::opponentIds($state, $playerId), true)) {
            throw new InvalidChoiceException('Vengeance must target one of your opponents');
        }
        $candidates = CustomEffectSupport::highestValueMoodIds($state, $targetPlayerId);
        if ($candidates === []) {
            throw new InvalidChoiceException("Player {$targetPlayerId} has no moods in play");
        }

        return [new PendingDecisionRequest(
            key: self::KEY,
            targetPlayerId: $targetPlayerId,
            decisionType: 'vengeance_discard_mood',
            field: [
                'key' => self::KEY,
                'type' => 'mood',
                'candidate_card_ids' => $candidates,
                'required' => true,
                'label' => 'Vengeance: choose one of your highest value moods to put in the discard pile',
            ],
        )];
    }

    public function resolveDecisions(BoardState $state, int $cardId, int $playerId, PlayerChoices $choices, array $answers): array
    {
        if (!isset($answers[self::KEY])) {
            return [];
        }

        $targetPlayerId = $choices->requireInt('target_player_id');
        $discardedCardId = $answers[self::KEY]->requireInt(self::KEY);
        if (!in_array($discardedCardId, CustomEffectSupport::highestValueMoodIds($state, $targetPlayerId), true)) {
            throw new InvalidChoiceException("Card {$discardedCardId} is not one of player {$targetPlayerId}'s highest value moods");
        }

        $state->moveInPlayToDiscard($discardedCardId);

        return [];
    }
}
