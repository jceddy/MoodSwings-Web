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
 * round, each of your moods of that color has its value increased by 2. (This includes moods you play
 * later this round.)" The choice is recorded as a round-long (player, color, +2) bonus on the board
 * (BoardState::addRoundColorBonus(), lifted at the end of the round), so it covers your moods of that color
 * whenever they were played, and carries on if Vitality itself leaves play. "Your" is whoever owns the mood
 * at the time: a mood taken from you stops counting for you. Using it twice in a round (two Vitalities)
 * stacks.
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
            field: ['key' => self::KEY, 'type' => 'mode', 'required' => true, 'options' => self::COLORS, 'label' => 'Vitality: choose a color -- your moods of that color (including ones you play later) get +2 until the end of the round'],
        )];
    }

    public function applyTurnStart(BoardState $state, int $cardId, int $playerId, array $answers): array
    {
        $color = $answers[self::KEY]->requireString(self::KEY);
        if (!in_array($color, self::COLORS, true)) {
            throw new InvalidChoiceException("'{$color}' is not a color");
        }

        $state->addRoundColorBonus($playerId, $color, self::BONUS);

        return [];
    }
}
