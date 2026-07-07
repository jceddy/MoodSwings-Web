<?php

declare(strict_types=1);

namespace MoodSwings\Rules\Effects;

use MoodSwings\Rules\AbstractMoodEffect;
use MoodSwings\Rules\BoardState;
use MoodSwings\Rules\Exceptions\InvalidChoiceException;
use MoodSwings\Rules\PlayerChoices;

/**
 * Intimidation: "After playing this mood, you may choose another player.
 * If you do, that player reveals a card from their hand and puts it into
 * your hand. You may play it as an additional mood this turn." The
 * revealed card is a genuine random pick from the target's hand -- same
 * rationale as Compulsion. The resulting grant is restricted to that
 * one specific card via the 'specific_card_ids' restriction type (see
 * BoardState::grantAllows()), not just any card sharing some trait.
 */
final class IntimidationEffect extends AbstractMoodEffect
{
    public function afterPlaying(BoardState $state, int $cardId, int $playerId, PlayerChoices $choices): void
    {
        if (!$choices->has('target_player_id')) {
            return;
        }

        $targetPlayerId = $choices->requireInt('target_player_id');
        if (!in_array($targetPlayerId, $state->playerOrder(), true)) {
            throw new InvalidChoiceException("Player {$targetPlayerId} is not a valid player");
        }
        if ($targetPlayerId === $playerId) {
            throw new InvalidChoiceException('Intimidation must target another player');
        }

        $hand = $state->hand($targetPlayerId);
        if ($hand === []) {
            return;
        }

        $revealedCardId = $hand[array_rand($hand)];
        $state->giveHandCardToPlayer($targetPlayerId, $playerId, $revealedCardId);
        $state->grantExtraPlay(1, ['type' => 'specific_card_ids', 'values' => [$revealedCardId]]);
    }
}
