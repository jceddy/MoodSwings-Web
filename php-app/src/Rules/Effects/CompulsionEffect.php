<?php

declare(strict_types=1);

namespace MoodSwings\Rules\Effects;

use MoodSwings\Rules\AbstractMoodEffect;
use MoodSwings\Rules\BoardState;
use MoodSwings\Rules\Exceptions\InvalidChoiceException;
use MoodSwings\Rules\PlayerChoices;

/**
 * Compulsion: "After playing this mood, choose another player. That
 * player chooses a card from their hand and gives it to you." Mandatory
 * (no "may"). The target player's own choice of which hand card to give
 * up is real hidden information with no interactive API to resolve it
 * mid-play, so -- same rationale as Instability's opponent choice over
 * public information -- it's resolved with a genuine random pick from
 * their hand.
 */
final class CompulsionEffect extends AbstractMoodEffect
{
    public function afterPlaying(BoardState $state, int $cardId, int $playerId, PlayerChoices $choices): void
    {
        $targetPlayerId = $choices->requireInt('target_player_id');
        if (!in_array($targetPlayerId, $state->playerOrder(), true)) {
            throw new InvalidChoiceException("Player {$targetPlayerId} is not a valid player");
        }
        if ($targetPlayerId === $playerId) {
            throw new InvalidChoiceException('Compulsion must target another player');
        }

        $hand = $state->hand($targetPlayerId);
        if ($hand === []) {
            return;
        }

        $givenCardId = $hand[array_rand($hand)];
        $state->giveHandCardToPlayer($targetPlayerId, $playerId, $givenCardId);
    }
}
