<?php

declare(strict_types=1);

namespace MoodSwings\Rules\Effects\Custom;

use MoodSwings\Rules\AbstractMoodEffect;
use MoodSwings\Rules\BoardState;
use MoodSwings\Rules\Exceptions\InvalidChoiceException;
use MoodSwings\Rules\PendingDecisionRequest;
use MoodSwings\Rules\PlayerChoices;
use MoodSwings\Rules\RequiresOpponentDecision;

/**
 * Fury's shape -- every qualifying player (the acting player too) answers their own decision, and nothing is
 * discarded until every answer is in -- for the two cards that pick the players by a board condition:
 *  - 'three_or_more' (Heartlessness): "Each player with three or more moods puts one of their moods into the
 *    discard pile." Any of their moods (the played Heartlessness is already in play, so it counts and can go).
 *  - 'highest_total' (Righteousness): "Each player with the highest total value among their moods puts one of
 *    their highest value moods into the discard pile." Tied leaders all qualify; a player with no moods never does.
 * Which players qualify is worked out on the board as it stands when the card resolves, and again, unchanged,
 * when the answers arrive (nothing moves in between).
 */
final class QualifyingPlayersDiscardMoodEffect extends AbstractMoodEffect implements RequiresOpponentDecision
{
    public const THREE_OR_MORE = 'three_or_more';
    public const HIGHEST_TOTAL = 'highest_total';

    private const MOOD_COUNT_THRESHOLD = 3;
    private const KEY_PREFIX = 'discarded_mood_id_';

    public function __construct(private readonly string $condition, private readonly string $cardName)
    {
    }

    public function pendingDecisionsFor(BoardState $state, int $cardId, int $playerId, PlayerChoices $choices): array
    {
        $requests = [];
        foreach ($this->candidatesByPlayer($state) as $ownerId => $candidates) {
            $requests[] = new PendingDecisionRequest(
                key: self::KEY_PREFIX . $ownerId,
                targetPlayerId: $ownerId,
                decisionType: 'qualifying_players_discard_mood',
                field: [
                    'key' => self::KEY_PREFIX . $ownerId,
                    'type' => 'mood',
                    'candidate_card_ids' => $candidates,
                    'required' => true,
                    'label' => $this->condition === self::HIGHEST_TOTAL
                        ? "{$this->cardName}: choose one of your highest value moods to put in the discard pile"
                        : "{$this->cardName}: choose one of your moods to put in the discard pile",
                ],
            );
        }

        return $requests;
    }

    public function resolveDecisions(BoardState $state, int $cardId, int $playerId, PlayerChoices $choices, array $answers): array
    {
        $targets = [];
        foreach ($this->candidatesByPlayer($state) as $ownerId => $candidates) {
            $key = self::KEY_PREFIX . $ownerId;
            if (!isset($answers[$key])) {
                continue;
            }

            $discardedCardId = $answers[$key]->requireInt($key);
            if (!in_array($discardedCardId, $candidates, true)) {
                throw new InvalidChoiceException("Card {$discardedCardId} is not a mood player {$ownerId} may put into the discard pile");
            }
            $targets[] = $discardedCardId;
        }

        foreach ($targets as $targetCardId) {
            $state->moveInPlayToDiscard($targetCardId);
        }

        return [];
    }

    /**
     * playerId => the moods that player may put into the discard pile, for each player who qualifies.
     *
     * @return array<int, int[]>
     */
    private function candidatesByPlayer(BoardState $state): array
    {
        $result = [];
        if ($this->condition === self::THREE_OR_MORE) {
            foreach ($state->activePlayerOrder() as $ownerId) {
                $moodIds = array_map(static fn ($mood) => $mood->cardId, $state->moodsOwnedBy($ownerId));
                if (count($moodIds) >= self::MOOD_COUNT_THRESHOLD) {
                    $result[$ownerId] = $moodIds;
                }
            }

            return $result;
        }

        $totals = [];
        foreach ($state->activePlayerOrder() as $ownerId) {
            $total = 0;
            foreach ($state->moodsOwnedBy($ownerId) as $mood) {
                $total += $state->valueOf($mood->cardId);
            }
            $totals[$ownerId] = $total;
        }
        $highest = $totals === [] ? 0 : max($totals);
        foreach ($totals as $ownerId => $total) {
            if ($total !== $highest) {
                continue;
            }
            $candidates = CustomEffectSupport::highestValueMoodIds($state, $ownerId);
            if ($candidates !== []) {
                $result[$ownerId] = $candidates;
            }
        }

        return $result;
    }
}
