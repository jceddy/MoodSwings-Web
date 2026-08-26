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
 * chaos_068 (mythic, after_playing): "Choose any player who has two or
 * more moods (moods are cards in play). That player chooses two of their
 * moods. Put those moods and all other moods that share a color with
 * either of them into the discard pile." The chosen player's own "chooses
 * two of their moods" is that player's real hidden-information decision
 * (see ChaosRequiresOpponentDecision) -- a maintainer ruling reversing
 * this class's own original design (issue #405 follow-up, reported live
 * for chaos_086's identically-shaped case: "the other player should
 * choose the card from their hand to give to me -- it should not be
 * random"). A single multi-select field (rather than two sequential
 * one-mood requests) mirrors how an ordinary "choose exactly 2" field
 * already works elsewhere (e.g. Regret's own hand_mood_ids) -- both moods
 * are named in one answer.
 */
final class Chaos068Effect extends AbstractChaosMoodEffect implements ChaosRequiresOpponentDecision
{
    private const KEY = 'chosen_mood_ids';

    public function pendingDecisionsFor(BoardState $state, int $cardId, int $playerId, PlayerChoices $choices): array
    {
        $targetPlayerId = $choices->requireInt('target_player_id');
        if (!in_array($targetPlayerId, $state->activePlayerOrder(), true)) {
            throw new InvalidChoiceException("Player {$targetPlayerId} is not a valid player");
        }

        if (count($state->moodsOwnedBy($targetPlayerId)) < 2) {
            throw new InvalidChoiceException("Player {$targetPlayerId} does not have two or more moods");
        }

        return [
            new PendingDecisionRequest(
                key: self::KEY,
                targetPlayerId: $targetPlayerId,
                decisionType: 'chaos_068_choose_moods',
                field: [
                    'key' => self::KEY,
                    'type' => 'mood',
                    'scope' => 'own',
                    'multi' => true,
                    'count' => ['min' => 2, 'max' => 2],
                    'required' => true,
                    'label' => 'Choose two of your moods -- those moods, and every other mood sharing a color with either, will be discarded',
                ],
            ),
        ];
    }

    public function resolveDecisions(BoardState $state, int $cardId, int $playerId, PlayerChoices $choices, array $answers): array
    {
        if (!isset($answers[self::KEY])) {
            return [];
        }

        $targetPlayerId = $choices->requireInt('target_player_id');
        $chosenCardIds = array_unique($answers[self::KEY]->ints(self::KEY));
        if (count($chosenCardIds) !== 2) {
            throw new InvalidChoiceException('Choose exactly two moods');
        }

        foreach ($chosenCardIds as $chosenCardId) {
            if (!$state->isInPlay($chosenCardId) || $state->ownerOf($chosenCardId) !== $targetPlayerId) {
                throw new InvalidChoiceException("Card {$chosenCardId} is not one of player {$targetPlayerId}'s moods in play");
            }
        }

        $colors = array_map(static fn (int $chosenCardId) => $state->colorOf($chosenCardId), array_values($chosenCardIds));

        foreach ($state->moodsInPlay() as $mood) {
            if (in_array($mood->cardId, $chosenCardIds, true) || in_array($state->colorOf($mood->cardId), $colors, true)) {
                $state->moveInPlayToDiscard($mood->cardId);
            }
        }

        return [];
    }
}
