<?php

declare(strict_types=1);

namespace MoodSwings\Rules\Effects;

use MoodSwings\Rules\AbstractMoodEffect;
use MoodSwings\Rules\BoardState;
use MoodSwings\Rules\Exceptions\InvalidChoiceException;
use MoodSwings\Rules\PlayerChoices;

/**
 * Betrayal: "After playing this mood, give one of your moods to another
 * player. After scoring, that mood becomes yours again if it's still in
 * play." The given-away mood is tagged with the well-known
 * 'returnsToOwnerAfterScoring' effectState key (the original owner's id),
 * which GameService::applyAfterScoringHooks() resolves after every round
 * -- "if it's still in play" is automatic, since the tag is simply never
 * consulted for a mood that's left play by then.
 */
final class BetrayalEffect extends AbstractMoodEffect
{
    public function afterPlaying(BoardState $state, int $cardId, int $playerId, PlayerChoices $choices): void
    {
        $targetCardId = $choices->requireInt('target_mood_id');
        if (!$state->isInPlay($targetCardId) || $state->ownerOf($targetCardId) !== $playerId) {
            throw new InvalidChoiceException("Card {$targetCardId} is not one of player {$playerId}'s moods in play");
        }

        $recipientPlayerId = $choices->requireInt('recipient_player_id');
        if (!in_array($recipientPlayerId, $state->playerOrder(), true)) {
            throw new InvalidChoiceException("Player {$recipientPlayerId} is not a valid player");
        }
        if ($recipientPlayerId === $playerId) {
            throw new InvalidChoiceException('Betrayal must give the mood to another player');
        }

        $state->giveInPlayToPlayer($targetCardId, $recipientPlayerId);
        $state->setEffectState($targetCardId, 'returnsToOwnerAfterScoring', $playerId);
    }
}
