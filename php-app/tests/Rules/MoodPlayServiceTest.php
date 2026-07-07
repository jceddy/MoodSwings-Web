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

    public function testBenevolenceAllowsABonusPlayOfADifferentColor(): void
    {
        $state = $this->boardState(hands: [1 => [2, 106]]); // Benevolence (white), Zeal (red)
        $state->startTurn(1);

        $this->plays->playMood($state, 1, 2, new PlayerChoices([]));
        self::assertSame(1, $state->playsRemaining());

        $this->plays->playMood($state, 1, 106, new PlayerChoices([]));

        self::assertTrue($state->isInPlay(106));
        self::assertSame(0, $state->playsRemaining());
    }

    public function testBenevolenceRejectsABonusPlaySharingItsColor(): void
    {
        // The restriction is checked against whichever card is chosen for
        // the bonus play (here Dignity, also white), not Benevolence's own
        // color evaluated once up front.
        $state = $this->boardState(hands: [1 => [2, 8]]); // Benevolence, Dignity -- both white
        $state->startTurn(1);
        $this->plays->playMood($state, 1, 2, new PlayerChoices([]));

        $this->expectException(IllegalPlayException::class);
        $this->plays->playMood($state, 1, 8, new PlayerChoices([]));
    }

    public function testFriendlinessGrantsExtraPlayUsableOnlyOnAnEvenValuedCard(): void
    {
        $state = $this->boardState(hands: [1 => [13, 5]]); // Friendliness, Complacency (value 4, even)
        $state->startTurn(1);
        $this->plays->playMood($state, 1, 13, new PlayerChoices([]));

        $this->plays->playMood($state, 1, 5, new PlayerChoices([]));

        self::assertTrue($state->isInPlay(5));
        self::assertSame(0, $state->playsRemaining());
    }

    public function testFriendlinessRejectsAnOddValuedCardForItsExtraPlay(): void
    {
        $state = $this->boardState(hands: [1 => [13, 3]]); // Friendliness, Charity (value 1, odd)
        $state->startTurn(1);
        $this->plays->playMood($state, 1, 13, new PlayerChoices([]));

        $this->expectException(IllegalPlayException::class);
        $this->plays->playMood($state, 1, 3, new PlayerChoices([]));
    }

    public function testKindnessGrantsExtraPlayUsableOnlyOnAnOddValuedCard(): void
    {
        $state = $this->boardState(hands: [1 => [17, 7]]); // Kindness, Courage (value 1, odd)
        $state->startTurn(1);
        $this->plays->playMood($state, 1, 17, new PlayerChoices([]));

        $this->plays->playMood($state, 1, 7, new PlayerChoices([]));

        self::assertTrue($state->isInPlay(7));
        self::assertSame(0, $state->playsRemaining());
    }

    public function testKindnessRejectsAnEvenValuedCardForItsExtraPlay(): void
    {
        $state = $this->boardState(hands: [1 => [17, 5]]); // Kindness, Complacency (value 4, even)
        $state->startTurn(1);
        $this->plays->playMood($state, 1, 17, new PlayerChoices([]));

        $this->expectException(IllegalPlayException::class);
        $this->plays->playMood($state, 1, 5, new PlayerChoices([]));
    }

    public function testEagernessAllowsABonusPlaySharingItsColor(): void
    {
        $state = $this->boardState(hands: [1 => [114, 118]]); // Eagerness, Fascination -- both green
        $state->startTurn(1);
        $this->plays->playMood($state, 1, 114, new PlayerChoices([]));

        $this->plays->playMood($state, 1, 118, new PlayerChoices([]));

        self::assertTrue($state->isInPlay(118));
        self::assertSame(0, $state->playsRemaining());
    }

    public function testEagernessRejectsABonusPlayOfADifferentColor(): void
    {
        $state = $this->boardState(hands: [1 => [114, 55]]); // Eagerness (green), Apathy (black)
        $state->startTurn(1);
        $this->plays->playMood($state, 1, 114, new PlayerChoices([]));

        $this->expectException(IllegalPlayException::class);
        $this->plays->playMood($state, 1, 55, new PlayerChoices([]));
    }

    public function testFaithSuppressesTargetAndLinksItsSuppressionSourceToItself(): void
    {
        $state = $this->boardState(hands: [1 => [12, 27, 9]]); // Faith, Ambivalence (blue, qualifies) to discard, Discipline to suppress
        $state->moveHandToInPlay(1, 9); // Discipline, the suppression target
        $state->startTurn(1);

        $this->plays->playMood($state, 1, 12, new PlayerChoices(['discard_card_id' => 27, 'target_mood_id' => 9]));

        self::assertTrue($state->isSuppressed(9));
        self::assertSame(0, $state->valueOf(9));
        self::assertSame([27], $state->discardPile());

        // The suppression is tied to Faith as its source -- clearing
        // suppressions from Faith's card id lifts it, confirming the link.
        $state->clearSuppressionsFrom(12);
        self::assertFalse($state->isSuppressed(9));
    }

    public function testFaithRejectsNonQualifyingDiscardColor(): void
    {
        $state = $this->boardState(hands: [1 => [12, 55, 9]]); // Apathy is black, doesn't qualify
        $state->moveHandToInPlay(1, 9);
        $state->startTurn(1);

        $this->expectException(InvalidChoiceException::class);
        $this->plays->playMood($state, 1, 12, new PlayerChoices(['discard_card_id' => 55, 'target_mood_id' => 9]));
    }

    public function testFaithDoesNothingWhenDeclined(): void
    {
        $state = $this->boardState(hands: [1 => [12]]);
        $state->startTurn(1);

        $this->plays->playMood($state, 1, 12, new PlayerChoices([]));

        self::assertTrue($state->isInPlay(12));
        self::assertSame([], $state->discardPile());
    }

    public function testGuileDiscardsTwoHandCardsAndStealsAnOpponentsMood(): void
    {
        $state = $this->boardState(hands: [1 => [40, 55, 106], 2 => [56]]);
        $state->moveHandToInPlay(2, 56); // Betrayal, owned by player 2
        $state->startTurn(1);

        $this->plays->playMood($state, 1, 40, new PlayerChoices([
            'discard_card_ids' => [55, 106],
            'target_mood_id' => 56,
        ]));

        self::assertTrue($state->isInPlay(40));
        self::assertSame(1, $state->ownerOf(56));
        self::assertEqualsCanonicalizing([55, 106], $state->discardPile());
    }

    public function testGuileCannotBePlayedWithoutTwoOtherCardsToDiscard(): void
    {
        $state = $this->boardState(hands: [1 => [40, 55]]);
        $state->startTurn(1);

        $this->expectException(IllegalPlayException::class);
        $this->plays->playMood($state, 1, 40, new PlayerChoices(['discard_card_ids' => [55], 'target_mood_id' => 1]));
    }

    public function testGuileCannotTargetYourOwnMood(): void
    {
        $state = $this->boardState(hands: [1 => [40, 55, 106, 9]]);
        $state->moveHandToInPlay(1, 9);
        $state->startTurn(1);

        $this->expectException(InvalidChoiceException::class);
        $this->plays->playMood($state, 1, 40, new PlayerChoices([
            'discard_card_ids' => [55, 106],
            'target_mood_id' => 9,
        ]));
    }

    public function testEnvyCannotBePlayedWithNoMoodsAlreadyInPlay(): void
    {
        $state = $this->boardState(hands: [1 => [64]]);
        $state->startTurn(1);

        $this->expectException(IllegalPlayException::class);
        $this->plays->playMood($state, 1, 64, new PlayerChoices(['discard_mood_id' => 1]));
    }

    public function testEnvyPaysItsCostAndScalesWithTheMoodiestOpponent(): void
    {
        $state = $this->boardState(hands: [1 => [64, 9], 2 => [55, 106], 3 => [80]]);
        $state->moveHandToInPlay(1, 9); // the mood player 1 will sacrifice as Envy's cost
        $state->moveHandToInPlay(2, 55);
        $state->moveHandToInPlay(2, 106); // player 2 is the moodiest opponent with 2 moods
        $state->moveHandToInPlay(3, 80);
        $state->startTurn(1);

        $this->plays->playMood($state, 1, 64, new PlayerChoices(['discard_mood_id' => 9]));

        self::assertFalse($state->isInPlay(9));
        self::assertSame([9], $state->discardPile());
        self::assertSame(4, $state->valueOf(64)); // base 0 + 2 * 2 moods
    }

    public function testSadnessValueScalesWithDiscardPileSize(): void
    {
        $state = $this->boardState(hands: [1 => [74]], discard: [55, 106, 80]);
        $state->startTurn(1);

        $this->plays->playMood($state, 1, 74, new PlayerChoices([]));

        self::assertSame(6, $state->valueOf(74)); // base 0 + 2 * 3 discarded cards
    }

    public function testVanityTriplesItsPerMoodValueWhenHandIsEmpty(): void
    {
        $state = $this->boardState(hands: [1 => [79]]);
        $state->startTurn(1);

        $this->plays->playMood($state, 1, 79, new PlayerChoices([]));

        self::assertSame([], $state->hand(1));
        self::assertSame(3, $state->valueOf(79)); // base 0 + 3 * 1 mood (itself), hand empty
    }

    public function testVanityUsesTheNormalPerMoodValueWithCardsInHand(): void
    {
        $state = $this->boardState(hands: [1 => [79, 55]]);
        $state->startTurn(1);

        $this->plays->playMood($state, 1, 79, new PlayerChoices([]));

        self::assertSame([55], $state->hand(1));
        self::assertSame(1, $state->valueOf(79)); // base 0 + 1 * 1 mood (itself), hand non-empty
    }

    public function testFascinationBoostsValueWhenACardIsGivenAway(): void
    {
        $state = $this->boardState(hands: [1 => [118, 56], 2 => []]); // Betrayal is black, qualifies
        $state->startTurn(1);

        $this->plays->playMood($state, 1, 118, new PlayerChoices(['give_card_id' => 56, 'recipient_player_id' => 2]));

        self::assertSame(7, $state->valueOf(118));
        self::assertTrue($state->isInHand(2, 56));
        self::assertFalse($state->isInHand(1, 56));
    }

    public function testFascinationRejectsNonQualifyingCardColor(): void
    {
        $state = $this->boardState(hands: [1 => [118, 9], 2 => []]); // Discipline is white, doesn't qualify
        $state->startTurn(1);

        $this->expectException(InvalidChoiceException::class);
        $this->plays->playMood($state, 1, 118, new PlayerChoices(['give_card_id' => 9, 'recipient_player_id' => 2]));
    }

    public function testFascinationDoesNothingWhenDeclined(): void
    {
        $state = $this->boardState(hands: [1 => [118]]);
        $state->startTurn(1);

        $this->plays->playMood($state, 1, 118, new PlayerChoices([]));

        self::assertSame(3, $state->valueOf(118));
    }

    public function testWonderCountsMatchingColorMoodsAndDiscardedCards(): void
    {
        $state = $this->boardState(hands: [1 => [133], 2 => [56]], discard: [54]); // Betrayal + Angst, both black
        $state->moveHandToInPlay(2, 56);
        $state->startTurn(1);

        $this->plays->playMood($state, 1, 133, new PlayerChoices(['color' => 'black']));

        self::assertSame(4, $state->valueOf(133)); // base 0 + 2 * (1 in-play black mood + 1 black discarded card)
    }

    public function testWonderRejectsAnInvalidColor(): void
    {
        $state = $this->boardState(hands: [1 => [133]]);
        $state->startTurn(1);

        $this->expectException(InvalidChoiceException::class);
        $this->plays->playMood($state, 1, 133, new PlayerChoices(['color' => 'purple']));
    }

    public function testAngerDiscardsQualifyingMoodsWithinTheTotalValueLimit(): void
    {
        $state = $this->boardState(hands: [1 => [80], 2 => [3], 3 => [7]]);
        $state->moveHandToInPlay(2, 3); // Charity, value 1
        $state->moveHandToInPlay(3, 7); // Courage, value 1
        $state->startTurn(1);

        $this->plays->playMood($state, 1, 80, new PlayerChoices(['target_mood_ids' => [3, 7]]));

        self::assertFalse($state->isInPlay(3));
        self::assertFalse($state->isInPlay(7));
        self::assertEqualsCanonicalizing([3, 7], $state->discardPile());
    }

    public function testAngerRejectsExceedingTheTotalValueLimit(): void
    {
        $state = $this->boardState(hands: [1 => [80], 2 => [9]]);
        $state->moveHandToInPlay(2, 9); // Discipline, base value 6 > 5 on its own
        $state->startTurn(1);

        $this->expectException(InvalidChoiceException::class);
        $this->plays->playMood($state, 1, 80, new PlayerChoices(['target_mood_ids' => [9]]));
    }

    public function testAngerDoesNothingWhenDeclined(): void
    {
        $state = $this->boardState(hands: [1 => [80]]);
        $state->startTurn(1);

        $this->plays->playMood($state, 1, 80, new PlayerChoices([]));

        self::assertTrue($state->isInPlay(80));
        self::assertSame([], $state->discardPile());
    }

    public function testSelfLoathingDiscardsChosenOwnMoodsAsItsCost(): void
    {
        $state = $this->boardState(hands: [1 => [75, 9]]);
        $state->moveHandToInPlay(1, 9); // the mood sacrificed to pay Self-Loathing's cost
        $state->startTurn(1);

        $this->plays->playMood($state, 1, 75, new PlayerChoices(['discard_mood_ids' => [9]]));

        self::assertTrue($state->isInPlay(75));
        self::assertFalse($state->isInPlay(9));
        self::assertSame([9], $state->discardPile());
        self::assertSame(6, $state->valueOf(75));
    }

    public function testSelfLoathingCannotBePlayedWithNoMoodsInPlay(): void
    {
        $state = $this->boardState(hands: [1 => [75]]);
        $state->startTurn(1);

        $this->expectException(IllegalPlayException::class);
        $this->plays->playMood($state, 1, 75, new PlayerChoices(['discard_mood_ids' => []]));
    }

    public function testMeeknessSuppressesEveryQualifyingMoodRegardlessOfOwner(): void
    {
        $state = $this->boardState(hands: [1 => [19, 3], 2 => [9], 3 => [120]]);
        $state->moveHandToInPlay(1, 3); // Charity, value 1 -- too low to qualify
        $state->moveHandToInPlay(2, 9); // Discipline, value 6
        $state->moveHandToInPlay(3, 120); // Generosity, value 6
        $state->startTurn(1);

        $this->plays->playMood($state, 1, 19, new PlayerChoices([]));

        self::assertFalse($state->isSuppressed(3));
        self::assertTrue($state->isSuppressed(9));
        self::assertTrue($state->isSuppressed(120));
        self::assertSame(0, $state->valueOf(9));
    }

    public function testPacifismSuppressesOneChosenMoodPerPlayer(): void
    {
        $state = $this->boardState(hands: [1 => [20], 2 => [9], 3 => [120]]);
        $state->moveHandToInPlay(2, 9);
        $state->moveHandToInPlay(3, 120);
        $state->startTurn(1);

        $this->plays->playMood($state, 1, 20, new PlayerChoices(['target_mood_ids' => [9, 120]]));

        self::assertTrue($state->isSuppressed(9));
        self::assertTrue($state->isSuppressed(120));
    }

    public function testPacifismRejectsTwoTargetsFromTheSamePlayer(): void
    {
        $state = $this->boardState(hands: [1 => [20], 2 => [9, 3]]);
        $state->moveHandToInPlay(2, 9);
        $state->moveHandToInPlay(2, 3);
        $state->startTurn(1);

        $this->expectException(InvalidChoiceException::class);
        $this->plays->playMood($state, 1, 20, new PlayerChoices(['target_mood_ids' => [9, 3]]));
    }

    public function testPacifismRejectsMoreThanTwoTargets(): void
    {
        $state = $this->boardState(hands: [1 => [20, 3], 2 => [9], 3 => [120]]);
        $state->moveHandToInPlay(1, 3);
        $state->moveHandToInPlay(2, 9);
        $state->moveHandToInPlay(3, 120);
        $state->startTurn(1);

        $this->expectException(InvalidChoiceException::class);
        $this->plays->playMood($state, 1, 20, new PlayerChoices(['target_mood_ids' => [3, 9, 120]]));
    }

    public function testRepentanceSuppressesAllOtherMoodsWithTheChosenValueUntilEndOfRound(): void
    {
        $state = $this->boardState(hands: [1 => [23], 2 => [3], 3 => [7]]);
        $state->moveHandToInPlay(2, 3); // Charity, value 1
        $state->moveHandToInPlay(3, 7); // Courage, value 1
        $state->startTurn(1);

        $this->plays->playMood($state, 1, 23, new PlayerChoices(['value' => 1]));

        self::assertTrue($state->isSuppressed(3));
        self::assertTrue($state->isSuppressed(7));

        $state->clearEndOfRoundSuppressions();
        self::assertFalse($state->isSuppressed(3));
        self::assertFalse($state->isSuppressed(7));
    }

    public function testRepentanceRejectsAnOutOfRangeValue(): void
    {
        $state = $this->boardState(hands: [1 => [23]]);
        $state->startTurn(1);

        $this->expectException(InvalidChoiceException::class);
        $this->plays->playMood($state, 1, 23, new PlayerChoices(['value' => 13]));
    }

    public function testRepentanceDoesNothingWhenDeclined(): void
    {
        $state = $this->boardState(hands: [1 => [23]]);
        $state->startTurn(1);

        $this->plays->playMood($state, 1, 23, new PlayerChoices([]));

        self::assertTrue($state->isInPlay(23));
    }

    public function testHatePutsChosenMoodOnBottomOfDeckAndDrawsForTheActingPlayer(): void
    {
        $state = $this->boardState(hands: [1 => [66], 2 => [9]], deck: [99]);
        $state->moveHandToInPlay(2, 9);
        $state->startTurn(1);

        $this->plays->playMood($state, 1, 66, new PlayerChoices(['target_mood_id' => 9]));

        self::assertFalse($state->isInPlay(9));
        self::assertSame([9], $state->deck());
        self::assertTrue($state->isInHand(1, 99)); // the acting player draws, not 9's former owner
    }

    public function testHateDoesNothingWhenDeclined(): void
    {
        $state = $this->boardState(hands: [1 => [66]], deck: [99]);
        $state->startTurn(1);

        $this->plays->playMood($state, 1, 66, new PlayerChoices([]));

        self::assertTrue($state->isInPlay(66));
        self::assertSame([99], $state->deck());
    }

    public function testWrathDiscardsAllOtherMoodsWhenConfirmed(): void
    {
        $state = $this->boardState(hands: [1 => [105], 2 => [9], 3 => [120]]);
        $state->moveHandToInPlay(2, 9);
        $state->moveHandToInPlay(3, 120);
        $state->startTurn(1);

        $this->plays->playMood($state, 1, 105, new PlayerChoices(['discard_all_other_moods' => true]));

        self::assertTrue($state->isInPlay(105));
        self::assertFalse($state->isInPlay(9));
        self::assertFalse($state->isInPlay(120));
        self::assertEqualsCanonicalizing([9, 120], $state->discardPile());
    }

    public function testWrathDoesNothingWhenDeclined(): void
    {
        $state = $this->boardState(hands: [1 => [105], 2 => [9]]);
        $state->moveHandToInPlay(2, 9);
        $state->startTurn(1);

        $this->plays->playMood($state, 1, 105, new PlayerChoices([]));

        self::assertTrue($state->isInPlay(9));
        self::assertSame([], $state->discardPile());
    }

    public function testRageDiscardsOnlyLowValueMoodsWhenConfirmed(): void
    {
        $state = $this->boardState(hands: [1 => [98], 2 => [3], 3 => [9]]);
        $state->moveHandToInPlay(2, 3); // Charity, value 1 -- qualifies
        $state->moveHandToInPlay(3, 9); // Discipline, value 6 -- doesn't qualify
        $state->startTurn(1);

        $this->plays->playMood($state, 1, 98, new PlayerChoices(['discard_qualifying_moods' => true]));

        self::assertFalse($state->isInPlay(3));
        self::assertTrue($state->isInPlay(9));
        self::assertSame([3], $state->discardPile());
    }

    public function testRageDoesNothingWhenDeclined(): void
    {
        $state = $this->boardState(hands: [1 => [98], 2 => [3]]);
        $state->moveHandToInPlay(2, 3);
        $state->startTurn(1);

        $this->plays->playMood($state, 1, 98, new PlayerChoices([]));

        self::assertTrue($state->isInPlay(3));
    }

    public function testAnxietyReturnsOddValuedTargetsToTheirOwnersHands(): void
    {
        $state = $this->boardState(hands: [1 => [28], 2 => [3], 3 => [7]]);
        $state->moveHandToInPlay(2, 3); // Charity, value 1
        $state->moveHandToInPlay(3, 7); // Courage, value 1
        $state->startTurn(1);

        $this->plays->playMood($state, 1, 28, new PlayerChoices(['target_mood_ids' => [3, 7]]));

        self::assertFalse($state->isInPlay(3));
        self::assertFalse($state->isInPlay(7));
        self::assertTrue($state->isInHand(2, 3));
        self::assertTrue($state->isInHand(3, 7));
    }

    public function testAnxietyRejectsAnEvenValuedTarget(): void
    {
        $state = $this->boardState(hands: [1 => [28], 2 => [9]]);
        $state->moveHandToInPlay(2, 9); // Discipline, value 6
        $state->startTurn(1);

        $this->expectException(InvalidChoiceException::class);
        $this->plays->playMood($state, 1, 28, new PlayerChoices(['target_mood_ids' => [9]]));
    }

    public function testChivalryValueIs5WhenYouDidNotGoFirstThisRound(): void
    {
        $state = $this->boardState(hands: [1 => [4]]);
        $state->startRound(2);
        $state->startTurn(1);

        $this->plays->playMood($state, 1, 4, new PlayerChoices([]));

        self::assertSame(5, $state->valueOf(4));
    }

    public function testChivalryValueIsBaseWhenYouDidGoFirstThisRound(): void
    {
        $state = $this->boardState(hands: [1 => [4]]);
        $state->startRound(1);
        $state->startTurn(1);

        $this->plays->playMood($state, 1, 4, new PlayerChoices([]));

        self::assertSame(3, $state->valueOf(4));
    }

    public function testTriumphValueIs5WhenYouWentFirstThisRound(): void
    {
        $state = $this->boardState(hands: [1 => [104]]);
        $state->startRound(1);
        $state->startTurn(1);

        $this->plays->playMood($state, 1, 104, new PlayerChoices([]));

        self::assertSame(5, $state->valueOf(104));
    }

    public function testTriumphValueIsBaseWhenYouDidNotGoFirstThisRound(): void
    {
        $state = $this->boardState(hands: [1 => [104]]);
        $state->startRound(2);
        $state->startTurn(1);

        $this->plays->playMood($state, 1, 104, new PlayerChoices([]));

        self::assertSame(3, $state->valueOf(104));
    }

    public function testGuiltSuppressesASingleChosenBlackOrRedMood(): void
    {
        $state = $this->boardState(hands: [1 => [14], 2 => [56]]); // Guilt, Betrayal (black)
        $state->moveHandToInPlay(2, 56);
        $state->startTurn(1);

        $this->plays->playMood($state, 1, 14, new PlayerChoices(['mode' => 'single', 'target_mood_id' => 56]));

        self::assertTrue($state->isSuppressed(56));
    }

    public function testGuiltRejectsANonQualifyingSingleTarget(): void
    {
        $state = $this->boardState(hands: [1 => [14], 2 => [9]]); // Discipline is white, doesn't qualify
        $state->moveHandToInPlay(2, 9);
        $state->startTurn(1);

        $this->expectException(InvalidChoiceException::class);
        $this->plays->playMood($state, 1, 14, new PlayerChoices(['mode' => 'single', 'target_mood_id' => 9]));
    }

    public function testGuiltSuppressesAllBlackAndRedMoodsInAllMode(): void
    {
        $state = $this->boardState(hands: [1 => [14], 2 => [56], 3 => [80]]); // Betrayal (black), Anger (red)
        $state->moveHandToInPlay(2, 56);
        $state->moveHandToInPlay(3, 80);
        $state->startTurn(1);

        $this->plays->playMood($state, 1, 14, new PlayerChoices(['mode' => 'all']));

        self::assertTrue($state->isSuppressed(56));
        self::assertTrue($state->isSuppressed(80));
    }

    public function testShameSuppressesMoodsSharingTheDiscardedCardsColor(): void
    {
        $state = $this->boardState(hands: [1 => [25, 9], 2 => [8]]); // Discipline (white) to discard, Dignity (white) target
        $state->moveHandToInPlay(2, 8);
        $state->startTurn(1);

        $this->plays->playMood($state, 1, 25, new PlayerChoices(['discard_card_id' => 9]));

        self::assertTrue($state->isSuppressed(8));
        self::assertSame([9], $state->discardPile());
    }

    public function testShameDoesNothingWhenDeclined(): void
    {
        $state = $this->boardState(hands: [1 => [25]]);
        $state->startTurn(1);

        $this->plays->playMood($state, 1, 25, new PlayerChoices([]));

        self::assertTrue($state->isInPlay(25));
        self::assertSame([], $state->discardPile());
    }

    public function testBitternessDiscardsMoodsOfTheMostCommonColor(): void
    {
        $state = $this->boardState(hands: [1 => [57], 2 => [56], 3 => [9]]); // Bitterness + Betrayal both black, Discipline white
        $state->moveHandToInPlay(2, 56);
        $state->moveHandToInPlay(3, 9);
        $state->startTurn(1);

        $this->plays->playMood($state, 1, 57, new PlayerChoices([]));

        self::assertFalse($state->isInPlay(56));
        self::assertTrue($state->isInPlay(9)); // white -- not the most common color
        self::assertTrue($state->isInPlay(57)); // excluded from its own targets
        self::assertSame([56], $state->discardPile());
    }

    public function testSpiteDiscardsEvenValuedMoodsPerChosenPlayer(): void
    {
        $state = $this->boardState(hands: [1 => [76], 2 => [5], 3 => [55]]); // Complacency, Apathy -- both value 4 (even), vanilla
        $state->moveHandToInPlay(2, 5);
        $state->moveHandToInPlay(3, 55);
        $state->startTurn(1);

        $this->plays->playMood($state, 1, 76, new PlayerChoices(['target_mood_ids' => [5, 55]]));

        self::assertFalse($state->isInPlay(5));
        self::assertFalse($state->isInPlay(55));
        self::assertEqualsCanonicalizing([5, 55], $state->discardPile());
    }

    public function testSpiteRejectsAnOddValuedTarget(): void
    {
        $state = $this->boardState(hands: [1 => [76], 2 => [3]]); // Charity, value 1
        $state->moveHandToInPlay(2, 3);
        $state->startTurn(1);

        $this->expectException(InvalidChoiceException::class);
        $this->plays->playMood($state, 1, 76, new PlayerChoices(['target_mood_ids' => [3]]));
    }

    public function testRebellionDiscardsAllMoodsWithTheChosenValue(): void
    {
        $state = $this->boardState(hands: [1 => [99], 2 => [3], 3 => [7]]); // Charity, Courage -- both value 1
        $state->moveHandToInPlay(2, 3);
        $state->moveHandToInPlay(3, 7);
        $state->startTurn(1);

        $this->plays->playMood($state, 1, 99, new PlayerChoices(['value' => 1]));

        self::assertFalse($state->isInPlay(3));
        self::assertFalse($state->isInPlay(7));
        self::assertEqualsCanonicalizing([3, 7], $state->discardPile());
    }

    public function testRebellionRejectsAnOutOfRangeValue(): void
    {
        $state = $this->boardState(hands: [1 => [99]]);
        $state->startTurn(1);

        $this->expectException(InvalidChoiceException::class);
        $this->plays->playMood($state, 1, 99, new PlayerChoices(['value' => 4]));
    }

    public function testShockDiscardsLowValueMoodsPerChosenPlayer(): void
    {
        $state = $this->boardState(hands: [1 => [101], 2 => [3], 3 => [7]]); // Charity, Courage -- both value 1
        $state->moveHandToInPlay(2, 3);
        $state->moveHandToInPlay(3, 7);
        $state->startTurn(1);

        $this->plays->playMood($state, 1, 101, new PlayerChoices(['target_mood_ids' => [3, 7]]));

        self::assertFalse($state->isInPlay(3));
        self::assertFalse($state->isInPlay(7));
        self::assertEqualsCanonicalizing([3, 7], $state->discardPile());
    }

    public function testShockRejectsAHighValueTarget(): void
    {
        $state = $this->boardState(hands: [1 => [101], 2 => [9]]); // Discipline, value 6
        $state->moveHandToInPlay(2, 9);
        $state->startTurn(1);

        $this->expectException(InvalidChoiceException::class);
        $this->plays->playMood($state, 1, 101, new PlayerChoices(['target_mood_ids' => [9]]));
    }

    public function testFuryDiscardsEachPlayersHighestValueMood(): void
    {
        $state = $this->boardState(hands: [1 => [91, 3], 2 => [9], 3 => [7]]);
        $state->moveHandToInPlay(1, 3); // Charity, value 1 -- player 1's lower-value mood
        $state->moveHandToInPlay(2, 9); // Discipline, value 6
        $state->moveHandToInPlay(3, 7); // Courage, value 1
        $state->startTurn(1);

        $this->plays->playMood($state, 1, 91, new PlayerChoices([])); // Fury itself, value 4

        self::assertFalse($state->isInPlay(91)); // player 1's highest (4 > 1)
        self::assertTrue($state->isInPlay(3)); // spared -- not player 1's highest
        self::assertFalse($state->isInPlay(9));
        self::assertFalse($state->isInPlay(7));
        self::assertEqualsCanonicalizing([91, 9, 7], $state->discardPile());
    }

    public function testBravadoDiscardsAnotherMoodAndGrantsAnExtraPlay(): void
    {
        $state = $this->boardState(hands: [1 => [84, 3, 106]]);
        $state->moveHandToInPlay(1, 3); // Charity, sacrificed as Bravado's cost
        $state->startTurn(1);

        $this->plays->playMood($state, 1, 84, new PlayerChoices(['discard_mood_id' => 3]));

        self::assertFalse($state->isInPlay(3));
        self::assertTrue($state->isInPlay(84));
        self::assertSame(1, $state->playsRemaining());

        $this->plays->playMood($state, 1, 106, new PlayerChoices([]));
        self::assertTrue($state->isInPlay(106));
    }

    public function testBravadoDoesNothingWhenDeclined(): void
    {
        $state = $this->boardState(hands: [1 => [84]]);
        $state->startTurn(1);

        $this->plays->playMood($state, 1, 84, new PlayerChoices([]));

        self::assertTrue($state->isInPlay(84));
        self::assertSame(0, $state->playsRemaining());
    }

    public function testBravadoRejectsDiscardingItself(): void
    {
        $state = $this->boardState(hands: [1 => [84]]);
        $state->startTurn(1);

        $this->expectException(InvalidChoiceException::class);
        $this->plays->playMood($state, 1, 84, new PlayerChoices(['discard_mood_id' => 84]));
    }
}
