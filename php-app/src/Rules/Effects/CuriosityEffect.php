<?php

declare(strict_types=1);

namespace MoodSwings\Rules\Effects;

use MoodSwings\Rules\AbstractMoodEffect;
use MoodSwings\Rules\BoardState;
use MoodSwings\Rules\Exceptions\InvalidChoiceException;
use MoodSwings\Rules\PlayerChoices;

/**
 * Curiosity: "After playing this mood, you may choose a player. If you
 * do, that player reveals a random card from their hand. If the revealed
 * card shares a color with any mood, this mood's value becomes 6." The
 * revealed card is only looked at, not moved -- it stays in the target's
 * hand either way, so once this request/response is over its identity is
 * just as invisible to everyone but the acting player as Paranoia's own
 * reveal would be -- see recordRevealedCard()'s own docblock for why this
 * is logged the same way.
 */
final class CuriosityEffect extends AbstractMoodEffect
{
    private const BOOSTED_VALUE = 6;

    public function afterPlaying(BoardState $state, int $cardId, int $playerId, PlayerChoices $choices): void
    {
        $targetPlayerId = $choices->int('target_player_id');
        if ($targetPlayerId === null) {
            return;
        }

        $hand = $state->hand($targetPlayerId);
        if ($hand === []) {
            throw new InvalidChoiceException("Player {$targetPlayerId} has no cards in hand");
        }

        $revealedCardId = $hand[array_rand($hand)];
        $state->recordRevealedCard($revealedCardId);
        $revealedColor = $state->colorOf($revealedCardId);

        foreach ($state->moodsInPlay() as $mood) {
            if ($state->colorOf($mood->cardId) === $revealedColor) {
                $state->setValueOverride($cardId, self::BOOSTED_VALUE);

                return;
            }
        }
    }
}
