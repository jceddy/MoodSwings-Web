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
 * Greed and Nosiness -- "Choose an opponent with one or more cards in their hand. That player reveals their
 * hand. ..." The reveal is to all players (it shows in the log and as the candidates), then the PLAYER WHO
 * PLAYED the card picks one of the revealed cards. $outcome:
 *  - 'take_and_redraw' (Greed): "Put a card of your choice from it into your hand, then that player draws a card."
 *  - 'discard' (Nosiness): "That player ... discards a card of your choice from it."
 */
final class RevealHandPickEffect extends AbstractMoodEffect implements RequiresOpponentDecision
{
    public const TAKE_AND_REDRAW = 'take_and_redraw';
    public const DISCARD = 'discard';

    private const KEY = 'picked_card_id';

    public function __construct(private readonly string $outcome, private readonly string $cardName)
    {
    }

    public function pendingDecisionsFor(BoardState $state, int $cardId, int $playerId, PlayerChoices $choices): array
    {
        if (!$choices->has('target_player_id')) {
            return [];
        }

        $targetPlayerId = $choices->requireInt('target_player_id');
        if (!in_array($targetPlayerId, CustomEffectSupport::opponentIds($state, $playerId), true)) {
            throw new InvalidChoiceException("{$this->cardName} must target one of your opponents");
        }
        $hand = $state->hand($targetPlayerId);
        if ($hand === []) {
            throw new InvalidChoiceException("Player {$targetPlayerId} has no cards in hand");
        }

        foreach ($hand as $handCardId) {
            $state->recordRevealedCard($handCardId);
        }

        return [CustomEffectSupport::revealedCardRequest(
            self::KEY,
            $playerId,
            $hand,
            $this->outcome === self::DISCARD
                ? "{$this->cardName}: choose a card from the revealed hand for that player to discard"
                : "{$this->cardName}: choose a card from the revealed hand to put into your hand",
        )];
    }

    public function resolveDecisions(BoardState $state, int $cardId, int $playerId, PlayerChoices $choices, array $answers): array
    {
        if (!isset($answers[self::KEY])) {
            return [];
        }

        $targetPlayerId = $choices->requireInt('target_player_id');
        $pickedCardId = $answers[self::KEY]->requireInt(self::KEY);
        if (!$state->isInHand($targetPlayerId, $pickedCardId)) {
            throw new InvalidChoiceException("Card {$pickedCardId} is not in player {$targetPlayerId}'s hand");
        }

        if ($this->outcome === self::DISCARD) {
            $state->moveHandToDiscard($targetPlayerId, $pickedCardId);

            return [];
        }

        $state->giveHandCardToPlayer($targetPlayerId, $playerId, $pickedCardId);
        $state->drawCard($targetPlayerId);

        return [];
    }
}
