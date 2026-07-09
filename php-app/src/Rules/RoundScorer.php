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
 * total, rather than computing a value for their own card. Two of them
 * are printed with no "may" at all -- Exhilaration (doubles the owner's
 * whole total) and Bliss (triples the owner's moods sharing a color with
 * whatever card paid its cost) -- so there's no legitimate choice to make
 * regardless of anything else in play; both stay unconditional. The other
 * two are printed as "you may" -- Enthusiasm (score your own single
 * highest-valued mood an extra time) and Passion (score one of your
 * opponents' moods as though it were yours, on top of them still scoring
 * it too) -- and unlike Exhilaration/Bliss, declining one of these can
 * genuinely be correct: a card like Sneakiness swaps its owner's own
 * final score with a chosen opponent's, so inflating your own pre-swap
 * score isn't always in your interest. Those two are resolved from
 * $scoringDecisions instead of being computed automatically -- see
 * GameService's scoring-decision pause, which asks each Enthusiasm/
 * Passion owner explicitly rather than assuming the maximum is always
 * what they'd want.
 */
final class RoundScorer
{
    private const AUTOMATIC_SCORE_MULTIPLYING_EFFECT_KEYS = ['exhilaration', 'bliss'];

    /** Enthusiasm/Passion -- see this class's own docblock for why these two, unlike Exhilaration/Bliss, need an explicit decision rather than being computed automatically. */
    public const DECISION_SCORE_MULTIPLYING_EFFECT_KEYS = ['enthusiasm', 'passion'];

    /**
     * @param array<int, int> $scoringDecisions cardId => the already-
     *     resolved bonus amount for that specific Enthusiasm/Passion card
     *     this round (0 if declined) -- see GameService::
     *     resolveScoringDecisionBonus(). A card with no entry here
     *     contributes 0, which is what lets this same method serve both
     *     as the final score, once every needed entry is present, and as
     *     a live preview of scores-so-far while decisions are still
     *     outstanding (undecided cards simply read as "declined for now").
     * @return array<int, int> playerId => score
     */
    public function score(BoardState $state, array $scoringDecisions = []): array
    {
        $scores = array_fill_keys($state->playerOrder(), 0);
        foreach ($state->moodsInPlay() as $mood) {
            $scores[$mood->ownerId] += $state->valueOf($mood->cardId);
        }

        foreach ($state->moodsInPlay() as $mood) {
            $effectKey = $state->catalogRow($state->effectiveCardId($mood->cardId))['effectKey'];

            if (in_array($effectKey, self::AUTOMATIC_SCORE_MULTIPLYING_EFFECT_KEYS, true)) {
                $scores[$mood->ownerId] += match ($effectKey) {
                    'exhilaration' => $this->sumOwnMoods($state, $mood->ownerId),
                    'bliss' => 2 * $this->sumOwnMoodsSharingColor($state, $mood->ownerId, $state->effectState($mood->cardId, 'blissColor')),
                };
            } elseif (in_array($effectKey, self::DECISION_SCORE_MULTIPLYING_EFFECT_KEYS, true)) {
                $scores[$mood->ownerId] += $scoringDecisions[$mood->cardId] ?? 0;
            }
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

    /**
     * Enthusiasm's own candidate value if its owner accepts the bonus --
     * exposed publicly (unlike the other private helpers above) since
     * GameService needs it both to label the decision prompt with the
     * specific mood/value at stake and to resolve the final bonus once
     * accepted.
     */
    public function highestOwnMoodValue(BoardState $state, int $ownerId): int
    {
        $values = array_map(
            static fn ($mood) => $state->valueOf($mood->cardId),
            $state->moodsOwnedBy($ownerId),
        );

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
