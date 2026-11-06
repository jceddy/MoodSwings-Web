<?php

declare(strict_types=1);

namespace MoodSwings\Rules\Effects\Custom;

use MoodSwings\Rules\AbstractMoodEffect;
use MoodSwings\Rules\BoardState;
use MoodSwings\Rules\Exceptions\InvalidChoiceException;
use MoodSwings\Rules\PlayerChoices;

/**
 * Shrewdness: "After playing this mood -- You may choose one of your other moods and one of an opponent's moods with a value less
 * than or equal to that mood's. If you do, you and that opponent swap them." Both choices or neither. Values are the current ones
 * when it resolves; the swap is of ownership only -- both moods stay in play and neither after-playing effect happens again. The
 * opponent's mood is a normal opposing-mood choice (Solace and Reassurance can protect it).
 */
final class ShrewdnessEffect extends AbstractMoodEffect
{
    public function afterPlaying(BoardState $state, int $cardId, int $playerId, PlayerChoices $choices): void
    {
        $ownCardId = $choices->int('own_mood_id');
        $theirCardId = $choices->int('opponent_mood_id');
        if ($ownCardId === null && $theirCardId === null) {
            return;
        }
        if ($ownCardId === null || $theirCardId === null) {
            throw new InvalidChoiceException('Shrewdness swaps one of your moods for one of an opponent\'s: choose both or neither');
        }

        if ($ownCardId === $cardId || !$state->isInPlay($ownCardId) || $state->ownerOf($ownCardId) !== $playerId) {
            throw new InvalidChoiceException("Card {$ownCardId} is not one of player {$playerId}'s other moods in play");
        }
        if (!$state->isInPlay($theirCardId) || !in_array($state->ownerOf($theirCardId), CustomEffectSupport::opponentIds($state, $playerId), true)) {
            throw new InvalidChoiceException("Card {$theirCardId} is not one of your opponents' moods in play");
        }
        if ($state->valueOf($theirCardId) > $state->valueOf($ownCardId)) {
            throw new InvalidChoiceException("Card {$theirCardId} has a higher value than the mood you would give up");
        }

        $opponentId = $state->ownerOf($theirCardId);
        $state->giveInPlayToPlayer($ownCardId, $opponentId);
        $state->giveInPlayToPlayer($theirCardId, $playerId);
    }
}
