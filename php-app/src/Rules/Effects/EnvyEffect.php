<?php

declare(strict_types=1);

namespace MoodSwings\Rules\Effects;

use MoodSwings\Rules\AbstractMoodEffect;
use MoodSwings\Rules\BoardState;
use MoodSwings\Rules\Exceptions\InvalidChoiceException;
use MoodSwings\Rules\PlayerChoices;

/**
 * Envy: "To play this card, put one of your moods into the discard pile.
 * If you can't do that, you can't play this card. While in play, this
 * mood's value increases by 2 for each mood your moodiest opponent (the
 * opponent with the most moods) has." The cost targets a mood you already
 * have in play (Envy itself isn't in play yet when its cost is paid), so
 * it's illegal to play at all with zero moods already on the board. In
 * Open Team Play, a teammate isn't an opponent and so can never be the
 * "moodiest opponent" -- see BoardState::isTeammate().
 */
final class EnvyEffect extends AbstractMoodEffect
{
    private const VALUE_PER_OPPONENT_MOOD = 2;

    public function canPayToPlayCost(BoardState $state, int $cardId, int $playerId, PlayerChoices $choices): bool
    {
        return $state->moodsOwnedBy($playerId) !== [];
    }

    public function payToPlayCost(BoardState $state, int $cardId, int $playerId, PlayerChoices $choices): void
    {
        $discardMoodId = $choices->requireInt('discard_mood_id');
        if (!$state->isInPlay($discardMoodId) || $state->ownerOf($discardMoodId) !== $playerId) {
            throw new InvalidChoiceException("Card {$discardMoodId} is not one of player {$playerId}'s moods in play");
        }

        $state->moveInPlayToDiscard($discardMoodId);
    }

    public function computeValue(BoardState $state, int $cardId): int
    {
        $ownerId = $state->ownerOf($cardId);

        $moodiestOpponentCount = 0;
        foreach ($state->playerOrder() as $playerId) {
            if ($playerId !== $ownerId && !$state->isTeammate($ownerId, $playerId)) {
                $moodiestOpponentCount = max($moodiestOpponentCount, count($state->moodsOwnedBy($playerId)));
            }
        }

        $row = $state->catalogRow($state->effectiveCardId($cardId));

        return $row['baseValue'] + self::VALUE_PER_OPPONENT_MOOD * $moodiestOpponentCount;
    }
}
