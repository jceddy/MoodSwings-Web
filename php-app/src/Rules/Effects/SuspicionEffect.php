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
 * Suspicion: "After playing this mood, choose any number of players.
 * Each chosen player discards a card from their hand." The text doesn't
 * say "at random" (contrast Paranoia/Cruelty/Indecisiveness, which do),
 * so which card each chosen player discards is their own choice -- see
 * RequiresOpponentDecision. Each chosen player gets one independent
 * decision, queued in the order they were chosen; there's no shared
 * post-processing once they're all answered, each discard just happens
 * on its own.
 */
final class SuspicionEffect extends AbstractMoodEffect implements RequiresOpponentDecision
{
    private const KEY_PREFIX = 'discarded_card_id_';

    public function pendingDecisionsFor(BoardState $state, int $cardId, int $playerId, PlayerChoices $choices): array
    {
        $chosenPlayers = array_unique($choices->ints('player_ids'));

        foreach ($chosenPlayers as $chosenPlayerId) {
            if ($state->hand($chosenPlayerId) === []) {
                throw new InvalidChoiceException("Player {$chosenPlayerId} has no cards in hand");
            }
        }

        $requests = [];
        foreach ($chosenPlayers as $chosenPlayerId) {
            $requests[] = new PendingDecisionRequest(
                key: self::KEY_PREFIX . $chosenPlayerId,
                targetPlayerId: $chosenPlayerId,
                decisionType: 'suspicion_discard_card',
                field: [
                    'key' => self::KEY_PREFIX . $chosenPlayerId,
                    'type' => 'hand_card',
                    'required' => true,
                    'label' => 'Choose a card from your hand to discard',
                ],
            );
        }

        return $requests;
    }

    public function resolveDecisions(BoardState $state, int $cardId, int $playerId, PlayerChoices $choices, array $answers): void
    {
        $chosenPlayers = array_unique($choices->ints('player_ids'));

        foreach ($chosenPlayers as $chosenPlayerId) {
            $key = self::KEY_PREFIX . $chosenPlayerId;
            if (!isset($answers[$key])) {
                continue;
            }

            $discardedCardId = $answers[$key]->requireInt($key);
            if (!$state->isInHand($chosenPlayerId, $discardedCardId)) {
                throw new InvalidChoiceException("Card {$discardedCardId} is not in player {$chosenPlayerId}'s hand");
            }
            $state->moveHandToDiscard($chosenPlayerId, $discardedCardId);
        }
    }
}
