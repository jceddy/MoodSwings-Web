<?php

declare(strict_types=1);

namespace MoodSwings\Tests\Rules;

use MoodSwings\Rules\BoardState;
use MoodSwings\Rules\DefaultEffectRegistry;
use MoodSwings\Rules\RoundScorer;
use PHPUnit\Framework\TestCase;

final class RoundScorerTest extends TestCase
{
    use CatalogFixture;

    private RoundScorer $scorer;

    protected function setUp(): void
    {
        $this->scorer = new RoundScorer();
    }

    private function boardState(array $hands = []): BoardState
    {
        return new BoardState(
            $this->sampleCatalog(),
            DefaultEffectRegistry::build(),
            [1, 2, 3],
            $hands,
        );
    }

    public function testScoreSumsEachPlayersMoodValues(): void
    {
        $state = $this->boardState(hands: [1 => [3, 8], 2 => [56], 3 => []]);
        $state->moveHandToInPlay(1, 3); // Charity, 1
        $state->moveHandToInPlay(1, 8); // Dignity, base 3
        $state->moveHandToInPlay(2, 56); // Betrayal, 6

        $scores = $this->scorer->score($state);

        self::assertSame([1 => 4, 2 => 6, 3 => 0], $scores);
    }

    public function testScoreIncludesPlayersWithNoMoods(): void
    {
        $state = $this->boardState();

        $scores = $this->scorer->score($state);

        self::assertSame([1 => 0, 2 => 0, 3 => 0], $scores);
    }

    public function testWinnerIsHighestScorer(): void
    {
        $winner = $this->scorer->winner([1 => 3, 2 => 6, 3 => 1], [1, 2, 3]);

        self::assertSame(2, $winner);
    }

    public function testWinnerTieGoesToEarliestTurnThisRound(): void
    {
        // Players 2 and 3 are tied for the highest score; turn order this
        // round was 3, 1, 2 -- so player 3 played earliest and wins the tie.
        $winner = $this->scorer->winner([1 => 2, 2 => 6, 3 => 6], [3, 1, 2]);

        self::assertSame(3, $winner);
    }

    public function testHurtFeelingsGoesToLowestScorer(): void
    {
        $loser = $this->scorer->hurtFeelings([1 => 3, 2 => 6, 3 => 1], [1, 2, 3]);

        self::assertSame(3, $loser);
    }

    public function testHurtFeelingsTieGoesToLatestTurnThisRound(): void
    {
        // Players 1 and 2 are tied for the lowest score; turn order this
        // round was 1, 2, 3 -- so player 2 played latest among the tied
        // players and gets Hurt Feelings (the opposite tie-break direction
        // from winner()).
        $loser = $this->scorer->hurtFeelings([1 => 2, 2 => 2, 3 => 6], [1, 2, 3]);

        self::assertSame(2, $loser);
    }

    public function testExhilarationDoublesItsOwnersWholeTotal(): void
    {
        $state = $this->boardState(hands: [1 => [89, 3, 8]]); // Exhilaration (0), Charity (1), Dignity (base 3)
        $state->moveHandToInPlay(1, 89);
        $state->moveHandToInPlay(1, 3);
        $state->moveHandToInPlay(1, 8);

        $scores = $this->scorer->score($state);

        self::assertSame([1 => 8, 2 => 0, 3 => 0], $scores);
    }

    public function testBlissTriplesOwnMoodsSharingTheDiscardedCardsColor(): void
    {
        $state = $this->boardState(hands: [1 => [108, 3, 8, 55]]); // Bliss, Charity (white), Dignity (white), Apathy (black)
        $state->moveHandToInPlay(1, 108);
        $state->moveHandToInPlay(1, 3);
        $state->moveHandToInPlay(1, 8);
        $state->moveHandToInPlay(1, 55);
        $state->setEffectState(108, 'blissColor', 'white');

        $scores = $this->scorer->score($state);

        // base: Bliss(2) + Charity(1) + Dignity(3) + Apathy(4) = 10
        // bonus: 2 * (Charity(1) + Dignity(3)) = 8 -- Apathy (black) excluded
        self::assertSame([1 => 18, 2 => 0, 3 => 0], $scores);
    }

    public function testBlissAddsNoBonusWithoutARecordedColor(): void
    {
        $state = $this->boardState(hands: [1 => [108]]);
        $state->moveHandToInPlay(1, 108);

        $scores = $this->scorer->score($state);

        self::assertSame([1 => 2, 2 => 0, 3 => 0], $scores);
    }

    public function testEnthusiasmAddsItsOwnersHighestValuedMoodAgain(): void
    {
        $state = $this->boardState(hands: [1 => [116, 3, 8]]); // Enthusiasm (0), Charity (1), Dignity (base 3)
        $state->moveHandToInPlay(1, 116);
        $state->moveHandToInPlay(1, 3);
        $state->moveHandToInPlay(1, 8);

        $scores = $this->scorer->score($state);

        self::assertSame([1 => 7, 2 => 0, 3 => 0], $scores);
    }

    public function testPassionAddsTheHighestValuedOpponentMoodWithoutRemovingItFromThem(): void
    {
        $state = $this->boardState(hands: [1 => [97], 2 => [8, 3]]); // Passion; Dignity (3), Charity (1) for p2
        $state->moveHandToInPlay(1, 97);
        $state->moveHandToInPlay(2, 8);
        $state->moveHandToInPlay(2, 3);

        $scores = $this->scorer->score($state);

        self::assertSame([1 => 3, 2 => 4, 3 => 0], $scores);
    }
}
