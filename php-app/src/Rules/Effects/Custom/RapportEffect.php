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
 * Rapport: "After playing this mood -- You may put a card from your hand on the bottom of the deck. If you do,
 * draw a card. If there are two or more white and/or black moods, you may do this a second time." The first
 * loot rides with the play (the optional 'hand_card_id'); the second is a follow-up decision asked once the
 * first has resolved, so it can pick from the hand as it stands then (the card just drawn included) -- and is
 * only offered when the allied-color count is met. Zeal's loot, up to twice.
 */
final class RapportEffect extends AbstractMoodEffect implements RequiresOpponentDecision
{
    private const FIRST = 'hand_card_id';
    private const SECOND = 'second_hand_card_id';

    public function pendingDecisionsFor(BoardState $state, int $cardId, int $playerId, PlayerChoices $choices): array
    {
        // Nothing to ask up front -- the first loot is a play-time choice; resolveDecisions() runs it (and asks
        // for the second) straight away.
        return [];
    }

    public function resolveDecisions(BoardState $state, int $cardId, int $playerId, PlayerChoices $choices, array $answers): array
    {
        if (isset($answers[self::SECOND])) {
            $secondCardId = $answers[self::SECOND]->int(self::SECOND);
            if ($secondCardId !== null) {
                $this->loot($state, $playerId, $secondCardId);
            }

            return [];
        }

        $firstCardId = $choices->int(self::FIRST);
        if ($firstCardId === null) {
            return [];
        }
        $this->loot($state, $playerId, $firstCardId);

        if (CustomEffectSupport::countMoodsOfColors($state, ['white', 'black']) < 2 || $state->hand($playerId) === []) {
            return [];
        }

        return [new PendingDecisionRequest(
            key: self::SECOND,
            targetPlayerId: $playerId,
            decisionType: 'rapport_second_loot',
            field: ['key' => self::SECOND, 'type' => 'hand_card', 'required' => false, 'label' => 'Rapport: you may put another card from your hand on the bottom of the deck and draw a card'],
        )];
    }

    private function loot(BoardState $state, int $playerId, int $handCardId): void
    {
        if (!$state->isInHand($playerId, $handCardId)) {
            throw new InvalidChoiceException("Card {$handCardId} is not in player {$playerId}'s hand");
        }

        $state->moveHandToBottomOfDeck($playerId, $handCardId);
        $state->drawCard($playerId);
    }
}
