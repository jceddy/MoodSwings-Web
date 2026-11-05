<?php

declare(strict_types=1);

namespace MoodSwings\Rules\Effects\Custom;

use MoodSwings\Rules\AbstractMoodEffect;
use MoodSwings\Rules\BoardState;
use MoodSwings\Rules\Exceptions\InvalidChoiceException;
use MoodSwings\Rules\PendingDecisionRequest;
use MoodSwings\Rules\TurnStartEffect;

/**
 * Vitality: "While in play -- At the start of each of your turns, choose a color. Until the end of this
 * round, each of your moods of that color has its value increased by 2." The bonus is stamped on the
 * moods that are yours and of that color when the trigger resolves (BoardState::addRoundValueBonus(),
 * lifted at the end of the round), so it outlives Vitality leaving play; a mood of that color that arrives
 * later in the round doesn't get it. Using it twice in a round (two Vitalities) stacks.
 */
final class VitalityEffect extends AbstractMoodEffect implements TurnStartEffect
{
    private const KEY = 'color';
    private const BONUS = 2;
    private const COLORS = ['white', 'blue', 'black', 'red', 'green'];

    public function turnStartRequests(BoardState $state, int $cardId, int $playerId): ?array
    {
        return [new PendingDecisionRequest(
            key: self::KEY,
            targetPlayerId: $playerId,
            decisionType: 'turn_start_trigger',
            field: ['key' => self::KEY, 'type' => 'mode', 'required' => true, 'options' => self::COLORS, 'label' => 'Vitality: choose a color -- your moods of that color get +2 until the end of the round'],
        )];
    }

    public function applyTurnStart(BoardState $state, int $cardId, int $playerId, array $answers): array
    {
        $color = $answers[self::KEY]->requireString(self::KEY);
        if (!in_array($color, self::COLORS, true)) {
            throw new InvalidChoiceException("'{$color}' is not a color");
        }

        foreach ($state->moodsOwnedBy($playerId) as $mood) {
            if ($state->colorOf($mood->cardId) === $color) {
                $state->addRoundValueBonus($mood->cardId, self::BONUS);
            }
        }

        return [];
    }
}
