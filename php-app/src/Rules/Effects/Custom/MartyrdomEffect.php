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
 * Martyrdom: "At the start of each of your turns, you may put one of your other moods into the discard
 * pile. If you do, each opponent discards a card from their hand." Each opponent with cards chooses
 * their own discard, asked as a follow-up once the sacrifice has been made.
 */
final class MartyrdomEffect extends AbstractMoodEffect implements TurnStartEffect
{
    private const SACRIFICE_KEY = 'sacrifice_mood_id';
    private const DISCARD_KEY_PREFIX = 'discard_card_id_';

    public function turnStartRequests(BoardState $state, int $cardId, int $playerId): ?array
    {
        $candidates = $this->otherOwnMoodIds($state, $cardId, $playerId);
        if ($candidates === []) {
            return [];
        }

        return [new PendingDecisionRequest(
            key: self::SACRIFICE_KEY,
            targetPlayerId: $playerId,
            decisionType: 'turn_start_trigger',
            field: [
                'key' => self::SACRIFICE_KEY, 'type' => 'mood', 'scope' => 'own', 'required' => false,
                'label' => 'Martyrdom: put one of your other moods into the discard pile (each opponent then discards a card)',
                'candidate_card_ids' => $candidates,
            ],
        )];
    }

    public function applyTurnStart(BoardState $state, int $cardId, int $playerId, array $answers): array
    {
        $sacrificed = false;
        foreach (CustomEffectSupport::opponentIds($state, $playerId) as $opponentId) {
            $key = self::DISCARD_KEY_PREFIX . $opponentId;
            if (!isset($answers[$key])) {
                continue;
            }
            $discardCardId = $answers[$key]->requireInt($key);
            if (!$state->isInHand($opponentId, $discardCardId)) {
                throw new InvalidChoiceException("Card {$discardCardId} is not in player {$opponentId}'s hand");
            }
            $state->moveHandToDiscard($opponentId, $discardCardId);
            $sacrificed = true;
        }
        if ($sacrificed) {
            return [];
        }

        $sacrificeCardId = ($answers[self::SACRIFICE_KEY] ?? null)?->int(self::SACRIFICE_KEY);
        if ($sacrificeCardId === null) {
            return [];
        }
        if (!in_array($sacrificeCardId, $this->otherOwnMoodIds($state, $cardId, $playerId), true)) {
            throw new InvalidChoiceException("Card {$sacrificeCardId} is not one of your other moods");
        }
        $state->moveInPlayToDiscard($sacrificeCardId);

        $requests = [];
        foreach (CustomEffectSupport::opponentIds($state, $playerId) as $opponentId) {
            if ($state->hand($opponentId) !== []) {
                $requests[] = new PendingDecisionRequest(
                    key: self::DISCARD_KEY_PREFIX . $opponentId,
                    targetPlayerId: $opponentId,
                    decisionType: 'turn_start_trigger',
                    field: ['key' => self::DISCARD_KEY_PREFIX . $opponentId, 'type' => 'hand_card', 'required' => true, 'label' => 'Martyrdom: discard a card from your hand'],
                );
            }
        }

        return $requests;
    }

    /** @return int[] */
    private function otherOwnMoodIds(BoardState $state, int $cardId, int $playerId): array
    {
        return array_values(array_filter(
            array_map(static fn ($mood) => $mood->cardId, $state->moodsOwnedBy($playerId)),
            static fn (int $id): bool => $id !== $cardId,
        ));
    }
}
