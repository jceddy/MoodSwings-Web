<?php

declare(strict_types=1);

namespace MoodSwings\Rules\ChaosEffects;

use MoodSwings\Rules\AbstractChaosMoodEffect;
use MoodSwings\Rules\BoardState;
use MoodSwings\Rules\ChaosRequiresOpponentDecision;
use MoodSwings\Rules\Exceptions\InvalidChoiceException;
use MoodSwings\Rules\PendingDecisionRequest;
use MoodSwings\Rules\PlayerChoices;

/**
 * chaos_096 (rare, after_playing): "You may choose two moods from the
 * same opponent. If you do, they choose one of those moods and gives it
 * to you, then you give them one of your moods." Which two candidate
 * moods to name, and which of the acting player's own moods goes the
 * other way, are the acting player's own synchronous choices
 * (`opponent_mood_card_ids`/`own_mood_card_id`, validated up front exactly
 * as before); which of the two NAMED moods the opponent actually gives up
 * is that opponent's own real hidden-information decision (see
 * ChaosRequiresOpponentDecision) -- a maintainer ruling reversing this
 * class's own original design (issue #405 follow-up, reported live for
 * chaos_086's identically-shaped case: "the other player should choose
 * the card from their hand to give to me -- it should not be random").
 * The opponent's own field is narrowed to just the two named moods via
 * 'candidate_card_ids', mirroring Chaos091Effect's own "tied for highest"
 * narrowing.
 */
final class Chaos096Effect extends AbstractChaosMoodEffect implements ChaosRequiresOpponentDecision
{
    private const KEY = 'given_mood_id';

    public function pendingDecisionsFor(BoardState $state, int $cardId, int $playerId, PlayerChoices $choices): array
    {
        $targetCardIds = array_unique($choices->ints('opponent_mood_card_ids'));
        if ($targetCardIds === []) {
            return [];
        }
        if (count($targetCardIds) !== 2) {
            throw new InvalidChoiceException('Choose exactly two moods from the same opponent');
        }

        [$firstCardId, $secondCardId] = array_values($targetCardIds);
        if (!$state->isInPlay($firstCardId) || !$state->isInPlay($secondCardId)) {
            throw new InvalidChoiceException('Both chosen moods must be in play');
        }
        $opponentId = $state->ownerOf($firstCardId);
        if ($state->ownerOf($secondCardId) !== $opponentId) {
            throw new InvalidChoiceException('Both chosen moods must belong to the same opponent');
        }
        if ($opponentId === $playerId || $state->isTeammate($playerId, $opponentId)) {
            throw new InvalidChoiceException('Only an opponent\'s moods can be chosen');
        }

        $givenBackCardId = $choices->requireInt('own_mood_card_id');
        if ($givenBackCardId === $cardId || !in_array($givenBackCardId, array_map(static fn ($mood) => $mood->cardId, $state->moodsOwnedBy($playerId)), true)) {
            throw new InvalidChoiceException("Card {$givenBackCardId} is not one of your moods");
        }

        return [
            new PendingDecisionRequest(
                key: self::KEY,
                targetPlayerId: $opponentId,
                decisionType: 'chaos_096_give_mood',
                field: [
                    'key' => self::KEY,
                    'type' => 'mood',
                    'candidate_card_ids' => [$firstCardId, $secondCardId],
                    'required' => true,
                    'label' => 'Choose which of these two moods to give up',
                ],
            ),
        ];
    }

    public function resolveDecisions(BoardState $state, int $cardId, int $playerId, PlayerChoices $choices, array $answers): array
    {
        if (!isset($answers[self::KEY])) {
            return [];
        }

        $targetCardIds = array_values(array_unique($choices->ints('opponent_mood_card_ids')));
        $opponentId = $state->ownerOf($targetCardIds[0]);
        $givenBackCardId = $choices->requireInt('own_mood_card_id');

        $takenCardId = $answers[self::KEY]->requireInt(self::KEY);
        if (!in_array($takenCardId, $targetCardIds, true)) {
            throw new InvalidChoiceException("Card {$takenCardId} is not one of the two named moods");
        }
        if (!$state->isInPlay($takenCardId) || $state->ownerOf($takenCardId) !== $opponentId) {
            throw new InvalidChoiceException("Card {$takenCardId} is not one of player {$opponentId}'s moods in play");
        }

        $state->giveInPlayToPlayer($takenCardId, $playerId);
        $state->giveInPlayToPlayer($givenBackCardId, $opponentId);

        return [];
    }
}
