<?php

declare(strict_types=1);

namespace MoodSwings\Rules\Effects\Custom;

use MoodSwings\Rules\AbstractMoodEffect;
use MoodSwings\Rules\BoardState;
use MoodSwings\Rules\Exceptions\InvalidChoiceException;
use MoodSwings\Rules\PendingDecisionRequest;
use MoodSwings\Rules\PlayerChoices;
use MoodSwings\Rules\TurnStartEffect;

/**
 * FOMO: "To play this card -- Discard a card from your hand. (If you can't do that, you can't play this
 * card.) While in play -- At the start of each of your turns, you may discard a card from your hand. If
 * you don't, put this mood into the discard pile." With an empty hand the discard can't be made, so the
 * trigger resolves itself without asking.
 */
final class FomoEffect extends AbstractMoodEffect implements TurnStartEffect
{
    private const KEY = 'discard_card_id';

    public function canPayToPlayCost(BoardState $state, int $cardId, int $playerId, PlayerChoices $choices): bool
    {
        return array_diff($state->hand($playerId), [$cardId]) !== [];
    }

    public function payToPlayCost(BoardState $state, int $cardId, int $playerId, PlayerChoices $choices): void
    {
        $discardCardId = $choices->requireInt('discard_card_id');
        if ($discardCardId === $cardId) {
            throw new InvalidChoiceException('FOMO cannot pay its own cost by discarding itself');
        }
        if (!$state->isInHand($playerId, $discardCardId)) {
            throw new InvalidChoiceException("Card {$discardCardId} is not in player {$playerId}'s hand");
        }

        $state->moveHandToDiscard($playerId, $discardCardId);
    }

    public function turnStartRequests(BoardState $state, int $cardId, int $playerId): ?array
    {
        if ($state->hand($playerId) === []) {
            return null;
        }

        return [new PendingDecisionRequest(
            key: self::KEY,
            targetPlayerId: $playerId,
            decisionType: 'turn_start_trigger',
            field: ['key' => self::KEY, 'type' => 'hand_card', 'required' => false, 'label' => 'FOMO: discard a card from your hand (or FOMO goes into the discard pile)'],
        )];
    }

    public function applyTurnStart(BoardState $state, int $cardId, int $playerId, array $answers): array
    {
        $discardCardId = ($answers[self::KEY] ?? null)?->int(self::KEY);
        if ($discardCardId !== null) {
            if (!$state->isInHand($playerId, $discardCardId)) {
                throw new InvalidChoiceException("Card {$discardCardId} is not in player {$playerId}'s hand");
            }
            $state->moveHandToDiscard($playerId, $discardCardId);

            return [];
        }

        $state->moveInPlayToDiscard($cardId);

        return [];
    }
}
