<?php

declare(strict_types=1);

namespace MoodSwings\Tests\Rules;

use MoodSwings\Rules\BoardState;
use MoodSwings\Rules\DefaultEffectRegistry;
use MoodSwings\Rules\Exceptions\IllegalPlayException;
use MoodSwings\Rules\Exceptions\InvalidChoiceException;
use MoodSwings\Rules\MoodPlayService;
use MoodSwings\Rules\PlayerChoices;
use PHPUnit\Framework\TestCase;

final class MoodPlayServiceTest extends TestCase
{
    use CatalogFixture;

    private MoodPlayService $plays;

    protected function setUp(): void
    {
        $this->plays = new MoodPlayService(DefaultEffectRegistry::build());
    }

    private function boardState(array $hands = [], array $deck = [], array $discard = []): BoardState
    {
        return new BoardState(
            $this->sampleCatalog(),
            DefaultEffectRegistry::build(),
            [1, 2, 3],
            $hands,
            $deck,
            $discard,
        );
    }

    public function testCannotPlayOutOfTurn(): void
    {
        $state = $this->boardState(hands: [1 => [3]]);
        $state->startTurn(2);

        $this->expectException(IllegalPlayException::class);
        $this->plays->playMood($state, 1, 3, new PlayerChoices([]));
    }

    public function testCannotPlayWithNoPlaysRemaining(): void
    {
        $state = $this->boardState(hands: [1 => [3]]);
        $state->startTurn(1);
        $state->consumePlay();

        $this->expectException(IllegalPlayException::class);
        $this->plays->playMood($state, 1, 3, new PlayerChoices([]));
    }

    public function testCannotPlayCardNotInHand(): void
    {
        $state = $this->boardState(hands: [1 => []]);
        $state->startTurn(1);

        $this->expectException(IllegalPlayException::class);
        $this->plays->playMood($state, 1, 3, new PlayerChoices([]));
    }

    public function testCharityGrantsAnExtraPlay(): void
    {
        $state = $this->boardState(hands: [1 => [3, 7]]);
        $state->startTurn(1);

        $this->plays->playMood($state, 1, 3, new PlayerChoices([]));

        self::assertTrue($state->isInPlay(3));
        self::assertSame(1, $state->playsRemaining());

        // The granted extra play is real: a second card can be played this turn.
        $this->plays->playMood($state, 1, 7, new PlayerChoices([]));
        self::assertTrue($state->isInPlay(7));
        self::assertSame(0, $state->playsRemaining());
    }

    public function testDignityBoostsValueWhenQualifyingCardDiscarded(): void
    {
        $state = $this->boardState(hands: [1 => [8, 2]]); // Dignity, Benevolence (base 2, qualifies)
        $state->startTurn(1);

        $this->plays->playMood($state, 1, 8, new PlayerChoices(['discard_card_id' => 2]));

        self::assertSame(5, $state->valueOf(8));
        self::assertSame([2], $state->discardPile());
    }

    public function testDignityWithoutDiscardKeepsBaseValue(): void
    {
        $state = $this->boardState(hands: [1 => [8]]);
        $state->startTurn(1);

        $this->plays->playMood($state, 1, 8, new PlayerChoices([]));

        self::assertSame(3, $state->valueOf(8));
    }

    public function testDignityRejectsNonQualifyingDiscard(): void
    {
        $state = $this->boardState(hands: [1 => [8, 55]]); // Apathy has base value 4, doesn't qualify (needs 0-3)
        $state->startTurn(1);

        $this->expectException(InvalidChoiceException::class);
        $this->plays->playMood($state, 1, 8, new PlayerChoices(['discard_card_id' => 55]));
    }

    public function testImaginationOverridesColorForOtherCards(): void
    {
        $state = $this->boardState(hands: [1 => [42, 56]]); // Imagination, Betrayal (printed black)
        $state->startTurn(1);

        $this->plays->playMood($state, 1, 42, new PlayerChoices(['color' => 'green']));
        $state->moveHandToInPlay(1, 56); // placed directly; Betrayal's own effect isn't under test here

        self::assertSame('green', $state->colorOf(56));
    }

    public function testImaginationRejectsInvalidColor(): void
    {
        $state = $this->boardState(hands: [1 => [42]]);
        $state->startTurn(1);

        $this->expectException(InvalidChoiceException::class);
        $this->plays->playMood($state, 1, 42, new PlayerChoices(['color' => 'purple']));
    }

    public function testPairedColorThresholdRespectsImaginationOverride(): void
    {
        // Discipline is worth 3 if two or more black and/or red moods are
        // in play. Both Discipline and Charity are printed white, but
        // Imagination set to black makes them count as black instead.
        $state = $this->boardState(hands: [1 => [42, 9, 3]]);
        $state->startTurn(1);
        $state->grantExtraPlay(2);

        $this->plays->playMood($state, 1, 42, new PlayerChoices(['color' => 'black'])); // Imagination
        $this->plays->playMood($state, 1, 9, new PlayerChoices([])); // Discipline, printed white -> now black
        $this->plays->playMood($state, 1, 3, new PlayerChoices([])); // Charity, printed white -> now black

        self::assertSame(3, $state->valueOf(9));
    }

    public function testCourageDiscardsQualifyingTargets(): void
    {
        $state = $this->boardState(hands: [1 => [7], 2 => [56], 3 => [120]]);
        $state->moveHandToInPlay(2, 56); // Betrayal, value 6
        $state->moveHandToInPlay(3, 120); // Generosity, value 6
        $state->startTurn(1);

        $this->plays->playMood($state, 1, 7, new PlayerChoices(['target_mood_ids' => [56, 120]]));

        self::assertFalse($state->isInPlay(56));
        self::assertFalse($state->isInPlay(120));
        self::assertEqualsCanonicalizing([56, 120], $state->discardPile());
    }

    public function testCourageRejectsLowValueTarget(): void
    {
        $state = $this->boardState(hands: [1 => [7], 2 => [5]]);
        $state->moveHandToInPlay(2, 5); // Complacency, base value 4 < 5
        $state->startTurn(1);

        $this->expectException(InvalidChoiceException::class);
        $this->plays->playMood($state, 1, 7, new PlayerChoices(['target_mood_ids' => [5]]));
    }

    public function testCourageRejectsMoreThanTwoTargets(): void
    {
        $state = $this->boardState(hands: [1 => [7, 9], 2 => [56], 3 => [120]]);
        $state->moveHandToInPlay(1, 9); // Discipline, base value 6, stays in play as a third target
        $state->moveHandToInPlay(2, 56);
        $state->moveHandToInPlay(3, 120);
        $state->startTurn(1);

        $this->expectException(InvalidChoiceException::class);
        $this->plays->playMood($state, 1, 7, new PlayerChoices(['target_mood_ids' => [9, 56, 120]]));
    }

    public function testConvictionBottomsTargetAndDrawsForItsOwner(): void
    {
        $state = $this->boardState(hands: [1 => [6], 2 => [56]], deck: [99]);
        $state->startTurn(1);
        $state->moveHandToInPlay(2, 56); // Betrayal, owned by player 2

        $this->plays->playMood($state, 1, 6, new PlayerChoices(['target_mood_id' => 56]));

        self::assertFalse($state->isInPlay(56));
        // 56 goes to the bottom of the deck (after 99), then its owner
        // immediately draws -- taking 99, the card that was already on top.
        self::assertSame([56], $state->deck());
        self::assertTrue($state->isInHand(2, 99));
    }

    public function testZealCyclesAHandCardWhenChosen(): void
    {
        $state = $this->boardState(hands: [1 => [106, 2]], deck: [99]);
        $state->startTurn(1);

        $this->plays->playMood($state, 1, 106, new PlayerChoices(['hand_card_id' => 2]));

        self::assertFalse($state->isInHand(1, 2));
        // 2 goes to the bottom of the deck (after 99), then the player
        // immediately draws -- taking 99, the card that was already on top.
        self::assertSame([2], $state->deck());
        self::assertTrue($state->isInHand(1, 99));
    }

    public function testZealDoesNothingWhenDeclined(): void
    {
        $state = $this->boardState(hands: [1 => [106, 2]], deck: [99]);
        $state->startTurn(1);

        $this->plays->playMood($state, 1, 106, new PlayerChoices([]));

        self::assertTrue($state->isInHand(1, 2));
        self::assertSame([99], $state->deck());
    }

    public function testCreativityCopyingAnAfterPlayingCardTriggersThatEffect(): void
    {
        $state = $this->boardState(hands: [1 => [32, 7]]); // Creativity, Charity extra card
        $state->startTurn(1);

        $this->plays->playMood($state, 1, 32, new PlayerChoices(['copy_card_id' => 3])); // copy Charity

        self::assertSame(3, $state->effectiveCardId(32));
        self::assertSame(1, $state->valueOf(32));
        // Charity's after-playing effect (grant an extra play) should have fired.
        self::assertSame(1, $state->playsRemaining());
    }

    public function testCreativityCopyingAWhileInPlayCardUsesItsComputedValue(): void
    {
        // Discipline itself is printed white, so copying it doesn't make
        // the copy count toward its own "black and/or red" threshold --
        // two *other* black moods are needed to push it to its alt value.
        $state = $this->boardState(hands: [1 => [32, 56, 54]]);
        $state->startTurn(1);

        $this->plays->playMood($state, 1, 32, new PlayerChoices(['copy_card_id' => 9])); // copy Discipline
        $state->moveHandToInPlay(1, 56); // Betrayal, black
        $state->moveHandToInPlay(1, 54); // Angst, black

        self::assertSame(3, $state->valueOf(32));
    }
}
