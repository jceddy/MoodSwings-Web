<?php

declare(strict_types=1);

namespace MoodSwings\Rules\Effects;

use MoodSwings\Rules\AbstractMoodEffect;
use MoodSwings\Rules\BoardState;
use MoodSwings\Rules\Exceptions\InvalidChoiceException;
use MoodSwings\Rules\PlayerChoices;

/**
 * Regret: "To play this card, put two of your moods into your hand. If
 * you can't do that, you can't play this card. After playing this mood,
 * put an opponent's mood into your hand." The after-playing effect steals
 * a mood directly into the acting player's own hand, not the target's --
 * see BoardState::moveInPlayToPlayersHand(). You may not choose yourself
 * (you're not your own opponent), and in Open Team Play you can't return
 * a teammate's mood to your hand either, for the same reason -- see
 * BoardState::isTeammate().
 */
final class RegretEffect extends AbstractMoodEffect
{
    private const COST_COUNT = 2;

    public function canPayToPlayCost(BoardState $state, int $cardId, int $playerId, PlayerChoices $choices): bool
    {
        return count($state->moodsOwnedBy($playerId)) >= self::COST_COUNT;
    }

    public function payToPlayCost(BoardState $state, int $cardId, int $playerId, PlayerChoices $choices): void
    {
        $targets = array_unique($choices->ints('hand_mood_ids'));
        if (count($targets) !== self::COST_COUNT) {
            throw new InvalidChoiceException('Regret requires returning exactly two of your moods to hand');
        }

        foreach ($targets as $targetCardId) {
            if (!$state->isInPlay($targetCardId) || $state->ownerOf($targetCardId) !== $playerId) {
                throw new InvalidChoiceException("Card {$targetCardId} is not one of player {$playerId}'s moods in play");
            }
        }

        foreach ($targets as $targetCardId) {
            $state->moveInPlayToHand($targetCardId);
        }
    }

    public function afterPlaying(BoardState $state, int $cardId, int $playerId, PlayerChoices $choices): void
    {
        $targetCardId = $choices->requireInt('target_mood_id');
        if (!$state->isInPlay($targetCardId)) {
            throw new InvalidChoiceException("Card {$targetCardId} is not in play");
        }
        $targetOwnerId = $state->ownerOf($targetCardId);
        if ($targetOwnerId === $playerId || $state->isTeammate($playerId, $targetOwnerId)) {
            throw new InvalidChoiceException("Regret can only target an opponent's mood");
        }

        $state->moveInPlayToPlayersHand($targetCardId, $playerId);
    }
}
