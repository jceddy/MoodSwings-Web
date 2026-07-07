<?php

declare(strict_types=1);

namespace MoodSwings\Rules;

use LogicException;

/**
 * Computes each player's score for a round and settles the two questions
 * scoring answers: who won, and (in 3+ player games) who gets Hurt
 * Feelings for next round. The two use opposite tie-break directions per
 * the Extended Rules: ties for the win go to whoever played *earliest*
 * this round, but ties for Hurt Feelings go to whoever played *latest*.
 *
 * score() also resolves the small cluster of cards whose "while in play"
 * ability multiplies how much of the board counts toward their owner's
 * total, rather than computing a value for their own card: Exhilaration
 * (doubles the owner's whole total), Bliss (triples the owner's moods
 * sharing a color with whatever card paid its cost), Enthusiasm (adds
 * the owner's own single highest-valued mood a second time), and Passion
 * (adds the single highest-valued opponent mood across the table, on top
 * of that opponent still scoring it too). All four are printed as "you
 * may", but since card values are never negative, taking the bonus is
 * always at least as good as declining it -- so each is applied
 * unconditionally rather than needing an interactive scoring-time
 * choice the engine has no API for yet.
 */
final class RoundScorer
{
    private const SCORE_MULTIPLYING_EFFECT_KEYS = ['exhilaration', 'bliss', 'enthusiasm', 'passion'];

    /** @return array<int, int> playerId => score */
    public function score(BoardState $state): array
    {
        $scores = array_fill_keys($state->playerOrder(), 0);
        foreach ($state->moodsInPlay() as $mood) {
            $scores[$mood->ownerId] += $state->valueOf($mood->cardId);
        }

        foreach ($state->moodsInPlay() as $mood) {
            $effectKey = $state->catalogRow($state->effectiveCardId($mood->cardId))['effectKey'];
            if (!in_array($effectKey, self::SCORE_MULTIPLYING_EFFECT_KEYS, true)) {
                continue;
            }

            $scores[$mood->ownerId] += match ($effectKey) {
                'exhilaration' => $this->sumOwnMoods($state, $mood->ownerId),
                'bliss' => 2 * $this->sumOwnMoodsSharingColor($state, $mood->ownerId, $state->effectState($mood->cardId, 'blissColor')),
                'enthusiasm' => $this->highestOwnMoodValue($state, $mood->ownerId),
                'passion' => $this->highestOpponentMoodValue($state, $mood->ownerId),
            };
        }

        return $scores;
    }

    private function sumOwnMoods(BoardState $state, int $ownerId): int
    {
        $total = 0;
        foreach ($state->moodsOwnedBy($ownerId) as $mood) {
            $total += $state->valueOf($mood->cardId);
        }

        return $total;
    }

    private function sumOwnMoodsSharingColor(BoardState $state, int $ownerId, ?string $color): int
    {
        if ($color === null) {
            return 0;
        }

        $total = 0;
        foreach ($state->moodsOwnedBy($ownerId) as $mood) {
            if ($state->colorOf($mood->cardId) === $color) {
                $total += $state->valueOf($mood->cardId);
            }
        }

        return $total;
    }

    private function highestOwnMoodValue(BoardState $state, int $ownerId): int
    {
        $values = array_map(
            static fn ($mood) => $state->valueOf($mood->cardId),
            $state->moodsOwnedBy($ownerId),
        );

        return $values === [] ? 0 : max($values);
    }

    private function highestOpponentMoodValue(BoardState $state, int $ownerId): int
    {
        $values = [];
        foreach ($state->moodsInPlay() as $mood) {
            if ($mood->ownerId !== $ownerId) {
                $values[] = $state->valueOf($mood->cardId);
            }
        }

        return $values === [] ? 0 : max($values);
    }

    /**
     * @param array<int, int> $scores playerId => score
     * @param int[] $turnOrderThisRound the order players took their turns this round, earliest first
     */
    public function winner(array $scores, array $turnOrderThisRound): int
    {
        $highest = max($scores);
        foreach ($turnOrderThisRound as $playerId) {
            if ($scores[$playerId] === $highest) {
                return $playerId;
            }
        }

        throw new LogicException('No winner could be determined from the given scores and turn order');
    }

    /**
     * @param array<int, int> $scores playerId => score
     * @param int[] $turnOrderThisRound the order players took their turns this round, earliest first
     */
    public function hurtFeelings(array $scores, array $turnOrderThisRound): int
    {
        $lowest = min($scores);
        foreach (array_reverse($turnOrderThisRound) as $playerId) {
            if ($scores[$playerId] === $lowest) {
                return $playerId;
            }
        }

        throw new LogicException('No Hurt Feelings holder could be determined from the given scores and turn order');
    }
}
