<?php

declare(strict_types=1);

namespace MoodSwings\Rules\Effects;

use MoodSwings\Rules\AbstractMoodEffect;
use MoodSwings\Rules\BoardState;
use MoodSwings\Rules\Exceptions\InvalidChoiceException;
use MoodSwings\Rules\PendingDecisionRequest;
use MoodSwings\Rules\PlayerChoices;
use MoodSwings\Rules\RequiresOpponentDecision;

/**
 * Malice: "After playing this mood, choose any player who has two or
 * more moods. That player chooses two of their moods. Put those moods,
 * and all other moods that share a color with either of them, into the
 * discard pile." Which two moods the target picks is their own real
 * decision -- see RequiresOpponentDecision -- and, unlike every other
 * card in this group, the answer is a pair rather than a single value.
 * Note this can discard Malice itself, if it happens to share a color
 * with one of the chosen moods -- the printed text doesn't exclude it
 * the way Disillusionment's "each *other* mood" does.
 */
final class MaliceEffect extends AbstractMoodEffect implements RequiresOpponentDecision
{
    private const MINIMUM_MOODS = 2;
    private const CHOSEN_COUNT = 2;
    private const KEY = 'chosen_mood_ids';

    public function pendingDecisionsFor(BoardState $state, int $cardId, int $playerId, PlayerChoices $choices): array
    {
        $targetPlayerId = $choices->int('target_player_id');
        if ($targetPlayerId === null) {
            return [];
        }

        $moods = $state->moodsOwnedBy($targetPlayerId);
        if (count($moods) < self::MINIMUM_MOODS) {
            throw new InvalidChoiceException("Player {$targetPlayerId} does not have two or more moods");
        }

        return [
            new PendingDecisionRequest(
                key: self::KEY,
                targetPlayerId: $targetPlayerId,
                decisionType: 'malice_choose_moods',
                field: [
                    'key' => self::KEY,
                    'type' => 'mood',
                    'scope' => 'own',
                    'multi' => true,
                    'count' => ['min' => self::CHOSEN_COUNT, 'max' => self::CHOSEN_COUNT],
                    'required' => true,
                    'label' => 'Choose two of your moods to sacrifice',
                ],
            ),
        ];
    }

    public function resolveDecisions(BoardState $state, int $cardId, int $playerId, PlayerChoices $choices, array $answers): void
    {
        if (!isset($answers[self::KEY])) {
            return;
        }

        $targetPlayerId = $choices->requireInt('target_player_id');
        $chosenCardIds = $answers[self::KEY]->ints(self::KEY);
        if (count($chosenCardIds) !== self::CHOSEN_COUNT || count(array_unique($chosenCardIds)) !== self::CHOSEN_COUNT) {
            throw new InvalidChoiceException('Malice requires choosing exactly two different moods');
        }
        foreach ($chosenCardIds as $chosenCardId) {
            if (!$state->isInPlay($chosenCardId) || $state->ownerOf($chosenCardId) !== $targetPlayerId) {
                throw new InvalidChoiceException("Card {$chosenCardId} is not one of player {$targetPlayerId}'s moods in play");
            }
        }

        $chosenColors = array_map(static fn (int $cid) => $state->colorOf($cid), $chosenCardIds);

        $targets = [];
        foreach ($state->moodsInPlay() as $mood) {
            if (in_array($mood->cardId, $chosenCardIds, true) || in_array($state->colorOf($mood->cardId), $chosenColors, true)) {
                $targets[] = $mood->cardId;
            }
        }

        foreach ($targets as $targetCardId) {
            $state->moveInPlayToDiscard($targetCardId);
        }
    }
}
