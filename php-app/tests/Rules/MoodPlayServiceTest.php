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
        // "Any mood" means any mood currently in play, not any of the 133
        // printed card designs in the abstract -- so Charity has to already
        // be on the table (here, another player's) before Creativity can
        // copy it.
        $state = $this->boardState(hands: [1 => [32, 7], 2 => [3]]);
        $state->startTurn(2);
        $this->plays->playMood($state, 2, 3, new PlayerChoices([])); // Charity, now in play

        $state->startTurn(1);
        $this->plays->playMood($state, 1, 32, new PlayerChoices(['copy_card_id' => 3])); // copy the in-play Charity

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
        $state = $this->boardState(hands: [1 => [32, 56, 54], 2 => [9]]);
        $state->startTurn(2);
        $this->plays->playMood($state, 2, 9, new PlayerChoices([])); // Discipline, now in play

        $state->startTurn(1);
        $this->plays->playMood($state, 1, 32, new PlayerChoices(['copy_card_id' => 9])); // copy the in-play Discipline
        $state->moveHandToInPlay(1, 56); // Betrayal, black
        $state->moveHandToInPlay(1, 54); // Angst, black

        self::assertSame(3, $state->valueOf(32));
    }

    public function testCreativityCannotCopyAMoodThatIsNotCurrentlyInPlay(): void
    {
        // Charity (3) is only ever referenced by its catalog id here --
        // never actually played -- so it isn't a legal copy target.
        $state = $this->boardState(hands: [1 => [32]]);
        $state->startTurn(1);

        $this->expectException(InvalidChoiceException::class);
        $this->plays->playMood($state, 1, 32, new PlayerChoices(['copy_card_id' => 3]));
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

    public function testSuperiorityValueIs7WhenOwnerHasMoreMoodsThanEveryOtherPlayer(): void
    {
        $state = $this->boardState(hands: [1 => [77, 3], 2 => [9], 3 => [7]]);
        $state->moveHandToInPlay(1, 3);
        $state->moveHandToInPlay(2, 9);
        $state->moveHandToInPlay(3, 7);
        $state->startTurn(1);

        $this->plays->playMood($state, 1, 77, new PlayerChoices([]));

        self::assertSame(7, $state->valueOf(77));
    }

    public function testSuperiorityValueIsBaseWhenATiedOrHigherOpponentExists(): void
    {
        $state = $this->boardState(hands: [1 => [77], 2 => [9]]);
        $state->moveHandToInPlay(2, 9);
        $state->startTurn(1);

        $this->plays->playMood($state, 1, 77, new PlayerChoices([]));

        self::assertSame(3, $state->valueOf(77));
    }

    public function testFondnessValueIs7WhenEveryPlayerHasAtLeastThreeMoods(): void
    {
        $state = $this->boardState(hands: [1 => [119, 3, 7], 2 => [9, 55, 56], 3 => [80, 106, 8]]);
        $state->moveHandToInPlay(1, 3);
        $state->moveHandToInPlay(1, 7);
        $state->moveHandToInPlay(2, 9);
        $state->moveHandToInPlay(2, 55);
        $state->moveHandToInPlay(2, 56);
        $state->moveHandToInPlay(3, 80);
        $state->moveHandToInPlay(3, 106);
        $state->moveHandToInPlay(3, 8);
        $state->startTurn(1);

        $this->plays->playMood($state, 1, 119, new PlayerChoices([]));

        self::assertSame(7, $state->valueOf(119));
    }

    public function testFondnessValueIsBaseWhenAnyPlayerHasFewerThanThreeMoods(): void
    {
        $state = $this->boardState(hands: [1 => [119]]);
        $state->startTurn(1);

        $this->plays->playMood($state, 1, 119, new PlayerChoices([]));

        self::assertSame(0, $state->valueOf(119));
    }

    public function testAnimosityValueIs5WhenAnOpponentHasThreeOrMoreCardsInHand(): void
    {
        $state = $this->boardState(hands: [1 => [81], 2 => [9, 55, 56]]);
        $state->startTurn(1);

        $this->plays->playMood($state, 1, 81, new PlayerChoices([]));

        self::assertSame(5, $state->valueOf(81));
    }

    public function testAnimosityValueIsBaseWhenNoOpponentQualifies(): void
    {
        $state = $this->boardState(hands: [1 => [81], 2 => [9]]);
        $state->startTurn(1);

        $this->plays->playMood($state, 1, 81, new PlayerChoices([]));

        self::assertSame(3, $state->valueOf(81));
    }

    public function testCelebrationValueIs7WhenOwnerHasMoreDistinctColorsThanEveryOtherPlayer(): void
    {
        $state = $this->boardState(hands: [1 => [109, 3], 2 => [9]]);
        $state->moveHandToInPlay(1, 3); // Charity, white -- owner now has green + white
        $state->moveHandToInPlay(2, 9); // Discipline, white -- opponent has only 1 color
        $state->startTurn(1);

        $this->plays->playMood($state, 1, 109, new PlayerChoices([]));

        self::assertSame(7, $state->valueOf(109));
    }

    public function testCelebrationValueIsBaseWhenATiedOrHigherOpponentExists(): void
    {
        $state = $this->boardState(hands: [1 => [109], 2 => [9, 3]]);
        $state->moveHandToInPlay(2, 9); // Discipline, white
        $state->moveHandToInPlay(2, 3); // Charity, white -- still only 1 distinct color, but ties owner's 1
        $state->startTurn(1);

        $this->plays->playMood($state, 1, 109, new PlayerChoices([]));

        self::assertSame(3, $state->valueOf(109));
    }

    public function testDeterminationValueIs6WhenThreeMoodsShareAColor(): void
    {
        $state = $this->boardState(hands: [1 => [112], 2 => [118], 3 => [133]]); // all green
        $state->moveHandToInPlay(2, 118);
        $state->moveHandToInPlay(3, 133);
        $state->startTurn(1);

        $this->plays->playMood($state, 1, 112, new PlayerChoices([]));

        self::assertSame(6, $state->valueOf(112));
    }

    public function testDeterminationValueIsBaseWhenNoColorReachesTheThreshold(): void
    {
        $state = $this->boardState(hands: [1 => [112], 2 => [9]]);
        $state->moveHandToInPlay(2, 9);
        $state->startTurn(1);

        $this->plays->playMood($state, 1, 112, new PlayerChoices([]));

        self::assertSame(3, $state->valueOf(112));
    }

    public function testSerenityValueIs6WithAnEvenMoodCount(): void
    {
        $state = $this->boardState(hands: [1 => [129, 3]]);
        $state->moveHandToInPlay(1, 3);
        $state->startTurn(1);

        $this->plays->playMood($state, 1, 129, new PlayerChoices([]));

        self::assertSame(6, $state->valueOf(129));
    }

    public function testSerenityValueIsBaseWithAnOddMoodCount(): void
    {
        $state = $this->boardState(hands: [1 => [129]]);
        $state->startTurn(1);

        $this->plays->playMood($state, 1, 129, new PlayerChoices([]));

        self::assertSame(3, $state->valueOf(129));
    }

    public function testTranquilityValueIs6WithAnOddMoodCount(): void
    {
        $state = $this->boardState(hands: [1 => [131]]);
        $state->startTurn(1);

        $this->plays->playMood($state, 1, 131, new PlayerChoices([]));

        self::assertSame(6, $state->valueOf(131));
    }

    public function testTranquilityValueIsBaseWithAnEvenMoodCount(): void
    {
        $state = $this->boardState(hands: [1 => [131, 3]]);
        $state->moveHandToInPlay(1, 3);
        $state->startTurn(1);

        $this->plays->playMood($state, 1, 131, new PlayerChoices([]));

        self::assertSame(3, $state->valueOf(131));
    }

    public function testEuphoriaValueScalesWithTotalMoodsInPlay(): void
    {
        $state = $this->boardState(hands: [1 => [117], 2 => [9], 3 => [7]]);
        $state->moveHandToInPlay(2, 9);
        $state->moveHandToInPlay(3, 7);
        $state->startTurn(1);

        $this->plays->playMood($state, 1, 117, new PlayerChoices([]));

        self::assertSame(3, $state->valueOf(117)); // base 0 + 1 * 3 moods in play
    }

    public function testSlothValueScalesWithOwnersHandSize(): void
    {
        $state = $this->boardState(hands: [1 => [130, 3, 7]]);
        $state->startTurn(1);

        $this->plays->playMood($state, 1, 130, new PlayerChoices([]));

        self::assertSame([3, 7], $state->hand(1));
        self::assertSame(5, $state->valueOf(130)); // base 3 + 1 * 2 remaining hand cards
    }

    public function testLoveValueIs12WhenAllFiveColorsArePresent(): void
    {
        $state = $this->boardState(hands: [1 => [127], 2 => [3, 42, 56], 3 => [106]]);
        $state->moveHandToInPlay(2, 3); // white
        $state->moveHandToInPlay(2, 42); // blue
        $state->moveHandToInPlay(2, 56); // black
        $state->moveHandToInPlay(3, 106); // red
        $state->startTurn(1);

        $this->plays->playMood($state, 1, 127, new PlayerChoices([])); // green

        self::assertSame(12, $state->valueOf(127));
    }

    public function testLoveValueIsBaseWhenAColorIsMissing(): void
    {
        $state = $this->boardState(hands: [1 => [127], 2 => [3]]);
        $state->moveHandToInPlay(2, 3); // white only -- blue/black/red missing
        $state->startTurn(1);

        $this->plays->playMood($state, 1, 127, new PlayerChoices([]));

        self::assertSame(4, $state->valueOf(127));
    }

    public function testNeurosisReturnsChosenMoodsToHandAsItsCost(): void
    {
        $state = $this->boardState(hands: [1 => [46, 9]]);
        $state->moveHandToInPlay(1, 9);
        $state->startTurn(1);

        $this->plays->playMood($state, 1, 46, new PlayerChoices(['hand_mood_ids' => [9]]));

        self::assertTrue($state->isInPlay(46));
        self::assertFalse($state->isInPlay(9));
        self::assertTrue($state->isInHand(1, 9));
    }

    public function testNeurosisCannotBePlayedWithNoMoodsInPlay(): void
    {
        $state = $this->boardState(hands: [1 => [46]]);
        $state->startTurn(1);

        $this->expectException(IllegalPlayException::class);
        $this->plays->playMood($state, 1, 46, new PlayerChoices(['hand_mood_ids' => []]));
    }

    public function testNeurosisRejectsAnEmptyChoice(): void
    {
        $state = $this->boardState(hands: [1 => [46, 9]]);
        $state->moveHandToInPlay(1, 9);
        $state->startTurn(1);

        $this->expectException(InvalidChoiceException::class);
        $this->plays->playMood($state, 1, 46, new PlayerChoices(['hand_mood_ids' => []]));
    }

    public function testRegretReturnsTwoMoodsAndStealsAnOpponentsMoodIntoItsOwnersHand(): void
    {
        $state = $this->boardState(hands: [1 => [50, 3, 7], 2 => [9]]);
        $state->moveHandToInPlay(1, 3);
        $state->moveHandToInPlay(1, 7);
        $state->moveHandToInPlay(2, 9);
        $state->startTurn(1);

        $this->plays->playMood($state, 1, 50, new PlayerChoices(['hand_mood_ids' => [3, 7], 'target_mood_id' => 9]));

        self::assertTrue($state->isInHand(1, 3));
        self::assertTrue($state->isInHand(1, 7));
        self::assertFalse($state->isInPlay(9));
        self::assertTrue($state->isInHand(1, 9)); // stolen into player 1's hand, not player 2's
    }

    public function testRegretCannotTargetItsOwnersMood(): void
    {
        $state = $this->boardState(hands: [1 => [50, 3, 7, 8]]);
        $state->moveHandToInPlay(1, 3);
        $state->moveHandToInPlay(1, 7);
        $state->moveHandToInPlay(1, 8);
        $state->startTurn(1);

        $this->expectException(InvalidChoiceException::class);
        $this->plays->playMood($state, 1, 50, new PlayerChoices(['hand_mood_ids' => [3, 7], 'target_mood_id' => 8]));
    }

    public function testRegretCannotBePlayedWithFewerThanTwoMoods(): void
    {
        $state = $this->boardState(hands: [1 => [50, 3]]);
        $state->moveHandToInPlay(1, 3);
        $state->startTurn(1);

        $this->expectException(IllegalPlayException::class);
        $this->plays->playMood($state, 1, 50, new PlayerChoices(['hand_mood_ids' => [3], 'target_mood_id' => 1]));
    }

    public function testCrueltyDiscardsARandomMoodFromEachQualifyingOpponent(): void
    {
        $state = $this->boardState(hands: [1 => [61], 2 => [9, 3], 3 => [7]]);
        $state->moveHandToInPlay(2, 9);
        $state->moveHandToInPlay(2, 3);
        $state->moveHandToInPlay(3, 7);
        $state->startTurn(1);

        $this->plays->playMood($state, 1, 61, new PlayerChoices(['opponent_player_ids' => [2]]));

        self::assertCount(1, $state->moodsOwnedBy(2));
        self::assertCount(1, $state->discardPile());
        self::assertTrue($state->isInPlay(7)); // player 3 wasn't chosen
    }

    public function testCrueltyRejectsAnOpponentWithFewerThanTwoMoods(): void
    {
        $state = $this->boardState(hands: [1 => [61], 2 => [9]]);
        $state->moveHandToInPlay(2, 9);
        $state->startTurn(1);

        $this->expectException(InvalidChoiceException::class);
        $this->plays->playMood($state, 1, 61, new PlayerChoices(['opponent_player_ids' => [2]]));
    }

    public function testCrueltyRejectsTargetingYourself(): void
    {
        $state = $this->boardState(hands: [1 => [61, 3, 7]]);
        $state->moveHandToInPlay(1, 3);
        $state->moveHandToInPlay(1, 7);
        $state->startTurn(1);

        $this->expectException(InvalidChoiceException::class);
        $this->plays->playMood($state, 1, 61, new PlayerChoices(['opponent_player_ids' => [1]]));
    }

    public function testIndecisivenessReturnsARandomMoodFromEachQualifyingOpponentToTheirHand(): void
    {
        $state = $this->boardState(hands: [1 => [43], 2 => [9, 3]]);
        $state->moveHandToInPlay(2, 9);
        $state->moveHandToInPlay(2, 3);
        $state->startTurn(1);

        $this->plays->playMood($state, 1, 43, new PlayerChoices(['opponent_player_ids' => [2]]));

        self::assertCount(1, $state->moodsOwnedBy(2));
        self::assertCount(1, $state->hand(2));
    }

    public function testRejectionDiscardsAQualifyingPairSharingAColor(): void
    {
        $state = $this->boardState(hands: [1 => [73], 2 => [3], 3 => [8]]); // Charity, Dignity -- both white
        $state->moveHandToInPlay(2, 3);
        $state->moveHandToInPlay(3, 8);
        $state->startTurn(1);

        $this->plays->playMood($state, 1, 73, new PlayerChoices(['target_mood_ids' => [3, 8]]));

        self::assertFalse($state->isInPlay(3));
        self::assertFalse($state->isInPlay(8));
        self::assertEqualsCanonicalizing([3, 8], $state->discardPile());
    }

    public function testRejectionRejectsANonQualifyingPair(): void
    {
        $state = $this->boardState(hands: [1 => [73], 2 => [9], 3 => [55]]); // different colors and values
        $state->moveHandToInPlay(2, 9);
        $state->moveHandToInPlay(3, 55);
        $state->startTurn(1);

        $this->expectException(InvalidChoiceException::class);
        $this->plays->playMood($state, 1, 73, new PlayerChoices(['target_mood_ids' => [9, 55]]));
    }

    public function testRejectionDoesNothingWhenDeclined(): void
    {
        $state = $this->boardState(hands: [1 => [73]]);
        $state->startTurn(1);

        $this->plays->playMood($state, 1, 73, new PlayerChoices([]));

        self::assertTrue($state->isInPlay(73));
    }

    public function testDenialReturnsAQualifyingPairToTheirOwnersHands(): void
    {
        $state = $this->boardState(hands: [1 => [34], 2 => [3], 3 => [8]]); // Charity, Dignity -- both white
        $state->moveHandToInPlay(2, 3);
        $state->moveHandToInPlay(3, 8);
        $state->startTurn(1);

        $this->plays->playMood($state, 1, 34, new PlayerChoices(['target_mood_ids' => [3, 8]]));

        self::assertTrue($state->isInHand(2, 3));
        self::assertTrue($state->isInHand(3, 8));
    }

    public function testDisorientationReturnsAllMatchingValueMoodsToHand(): void
    {
        $state = $this->boardState(hands: [1 => [35], 2 => [3], 3 => [7]]); // both value 1
        $state->moveHandToInPlay(2, 3);
        $state->moveHandToInPlay(3, 7);
        $state->startTurn(1);

        $this->plays->playMood($state, 1, 35, new PlayerChoices(['value' => 1]));

        self::assertTrue($state->isInHand(2, 3));
        self::assertTrue($state->isInHand(3, 7));
    }

    public function testDisorientationRejectsAnOutOfRangeValue(): void
    {
        $state = $this->boardState(hands: [1 => [35]]);
        $state->startTurn(1);

        $this->expectException(InvalidChoiceException::class);
        $this->plays->playMood($state, 1, 35, new PlayerChoices(['value' => 13]));
    }

    public function testDisorientationDoesNothingWhenDeclined(): void
    {
        $state = $this->boardState(hands: [1 => [35]]);
        $state->startTurn(1);

        $this->plays->playMood($state, 1, 35, new PlayerChoices([]));

        self::assertTrue($state->isInPlay(35));
    }

    public function testPanicReturnsUpToTwoChosenMoodsToTheirOwnersHands(): void
    {
        $state = $this->boardState(hands: [1 => [48], 2 => [9], 3 => [7]]);
        $state->moveHandToInPlay(2, 9);
        $state->moveHandToInPlay(3, 7);
        $state->startTurn(1);

        $this->plays->playMood($state, 1, 48, new PlayerChoices(['target_mood_ids' => [9, 7]]));

        self::assertTrue($state->isInHand(2, 9));
        self::assertTrue($state->isInHand(3, 7));
    }

    public function testPanicCannotTargetItself(): void
    {
        $state = $this->boardState(hands: [1 => [48]]);
        $state->startTurn(1);

        $this->expectException(InvalidChoiceException::class);
        $this->plays->playMood($state, 1, 48, new PlayerChoices(['target_mood_ids' => [48]]));
    }

    public function testWorryReturnsOwnMoodAndUpToTwoLowValueMoodsToHand(): void
    {
        $state = $this->boardState(hands: [1 => [52, 9], 2 => [3], 3 => [7]]);
        $state->moveHandToInPlay(1, 9); // Discipline, white -- qualifies for the first stage
        $state->moveHandToInPlay(2, 3); // Charity, value 1
        $state->moveHandToInPlay(3, 7); // Courage, value 1
        $state->startTurn(1);

        $this->plays->playMood($state, 1, 52, new PlayerChoices(['hand_mood_id' => 9, 'target_mood_ids' => [3, 7]]));

        self::assertTrue($state->isInHand(1, 9));
        self::assertTrue($state->isInHand(2, 3));
        self::assertTrue($state->isInHand(3, 7));
    }

    public function testWorryRejectsANonQualifyingOwnMoodColor(): void
    {
        $state = $this->boardState(hands: [1 => [52, 80]]); // Anger is red, doesn't qualify
        $state->moveHandToInPlay(1, 80);
        $state->startTurn(1);

        $this->expectException(InvalidChoiceException::class);
        $this->plays->playMood($state, 1, 52, new PlayerChoices(['hand_mood_id' => 80]));
    }

    public function testWorryDoesNothingWhenDeclined(): void
    {
        $state = $this->boardState(hands: [1 => [52]]);
        $state->startTurn(1);

        $this->plays->playMood($state, 1, 52, new PlayerChoices([]));

        self::assertTrue($state->isInPlay(52));
    }

    public function testWorrySecondStageRejectsAHighValueTarget(): void
    {
        $state = $this->boardState(hands: [1 => [52, 9], 2 => [56]]); // Betrayal, value 6 -- too high
        $state->moveHandToInPlay(1, 9);
        $state->moveHandToInPlay(2, 56);
        $state->startTurn(1);

        $this->expectException(InvalidChoiceException::class);
        $this->plays->playMood($state, 1, 52, new PlayerChoices(['hand_mood_id' => 9, 'target_mood_ids' => [56]]));
    }

    public function testContemptDiscardsASingleChosenGreenOrWhiteMood(): void
    {
        $state = $this->boardState(hands: [1 => [59], 2 => [3]]);
        $state->moveHandToInPlay(2, 3);
        $state->startTurn(1);

        $this->plays->playMood($state, 1, 59, new PlayerChoices(['mode' => 'single', 'target_mood_id' => 3]));

        self::assertFalse($state->isInPlay(3));
    }

    public function testContemptDiscardsAllGreenAndWhiteMoodsInAllMode(): void
    {
        $state = $this->boardState(hands: [1 => [59], 2 => [3], 3 => [118]]);
        $state->moveHandToInPlay(2, 3);
        $state->moveHandToInPlay(3, 118);
        $state->startTurn(1);

        $this->plays->playMood($state, 1, 59, new PlayerChoices(['mode' => 'all']));

        self::assertFalse($state->isInPlay(3));
        self::assertFalse($state->isInPlay(118));
    }

    public function testContemptDoesNothingWhenDeclined(): void
    {
        $state = $this->boardState(hands: [1 => [59]]);
        $state->startTurn(1);

        $this->plays->playMood($state, 1, 59, new PlayerChoices([]));

        self::assertTrue($state->isInPlay(59));
    }

    public function testHappinessValueIs8WhenAPlayerHasBothARedAndWhiteMood(): void
    {
        $state = $this->boardState(hands: [1 => [122], 2 => [106, 3]]);
        $state->moveHandToInPlay(2, 106); // Zeal, red
        $state->moveHandToInPlay(2, 3); // Charity, white
        $state->startTurn(1);

        $this->plays->playMood($state, 1, 122, new PlayerChoices([]));

        self::assertSame(8, $state->valueOf(122));
    }

    public function testHappinessValueIsBaseWhenNoPlayerHasBothColors(): void
    {
        $state = $this->boardState(hands: [1 => [122], 2 => [106]]);
        $state->moveHandToInPlay(2, 106);
        $state->startTurn(1);

        $this->plays->playMood($state, 1, 122, new PlayerChoices([]));

        self::assertSame(2, $state->valueOf(122));
    }

    public function testMiseryValueIs8WhenTwoDiscardedCardsShareAColor(): void
    {
        $state = $this->boardState(hands: [1 => [70]], discard: [3, 7]); // both white
        $state->startTurn(1);

        $this->plays->playMood($state, 1, 70, new PlayerChoices([]));

        self::assertSame(8, $state->valueOf(70));
    }

    public function testMiseryValueIsBaseWhenNoColorRepeatsInDiscard(): void
    {
        $state = $this->boardState(hands: [1 => [70]], discard: [3, 106]); // white, red
        $state->startTurn(1);

        $this->plays->playMood($state, 1, 70, new PlayerChoices([]));

        self::assertSame(2, $state->valueOf(70));
    }

    public function testEmbarrassmentBoostsValueWhenQualifyingCardDiscarded(): void
    {
        $state = $this->boardState(hands: [1 => [87, 9]]); // Discipline, value 6, qualifies (4/5/6)
        $state->startTurn(1);

        $this->plays->playMood($state, 1, 87, new PlayerChoices(['discard_card_id' => 9]));

        self::assertSame(5, $state->valueOf(87));
    }

    public function testCheerBoostsValueWhenQualifyingCardDiscarded(): void
    {
        $state = $this->boardState(hands: [1 => [110, 5]]); // Complacency, value 4, qualifies (0/2/4/6)
        $state->startTurn(1);

        $this->plays->playMood($state, 1, 110, new PlayerChoices(['discard_card_id' => 5]));

        self::assertSame(5, $state->valueOf(110));
    }

    public function testDelightRejectsANonQualifyingDiscard(): void
    {
        $state = $this->boardState(hands: [1 => [111, 9]]); // Discipline, value 6, doesn't qualify (1/3/5)
        $state->startTurn(1);

        $this->expectException(InvalidChoiceException::class);
        $this->plays->playMood($state, 1, 111, new PlayerChoices(['discard_card_id' => 9]));
    }

    public function testAmbitionDiscardsHandCardAndGrantsAnExtraPlay(): void
    {
        $state = $this->boardState(hands: [1 => [53, 9, 106]]);
        $state->startTurn(1);

        $this->plays->playMood($state, 1, 53, new PlayerChoices(['discard_card_id' => 9]));

        self::assertFalse($state->isInHand(1, 9));
        self::assertSame([9], $state->discardPile());
        self::assertSame(1, $state->playsRemaining());

        $this->plays->playMood($state, 1, 106, new PlayerChoices([]));
        self::assertTrue($state->isInPlay(106));
    }

    public function testAmbitionDoesNothingWhenDeclined(): void
    {
        $state = $this->boardState(hands: [1 => [53]]);
        $state->startTurn(1);

        $this->plays->playMood($state, 1, 53, new PlayerChoices([]));

        self::assertSame(0, $state->playsRemaining());
    }

    public function testThrillReturnsChosenMoodsAndGrantsThatManyExtraPlays(): void
    {
        $state = $this->boardState(hands: [1 => [103, 9, 3, 106]]);
        $state->moveHandToInPlay(1, 9);
        $state->moveHandToInPlay(1, 3);
        $state->startTurn(1);

        $this->plays->playMood($state, 1, 103, new PlayerChoices(['hand_mood_ids' => [9, 3]]));

        self::assertTrue($state->isInHand(1, 9));
        self::assertTrue($state->isInHand(1, 3));
        self::assertSame(2, $state->playsRemaining());

        $this->plays->playMood($state, 1, 106, new PlayerChoices([]));
        self::assertTrue($state->isInPlay(106));
        self::assertSame(1, $state->playsRemaining());
    }

    public function testThrillDoesNothingWhenDeclined(): void
    {
        $state = $this->boardState(hands: [1 => [103]]);
        $state->startTurn(1);

        $this->plays->playMood($state, 1, 103, new PlayerChoices([]));

        self::assertSame(0, $state->playsRemaining());
    }

    public function testFearGrantsAnUnconditionalExtraPlayAndOptionallyReturnsAMood(): void
    {
        $state = $this->boardState(hands: [1 => [38, 9, 106]]);
        $state->moveHandToInPlay(1, 9);
        $state->startTurn(1);

        $this->plays->playMood($state, 1, 38, new PlayerChoices(['hand_mood_id' => 9]));

        self::assertTrue($state->isInHand(1, 9));
        self::assertSame(1, $state->playsRemaining());

        $this->plays->playMood($state, 1, 106, new PlayerChoices([]));
        self::assertTrue($state->isInPlay(106));
    }

    public function testFearGrantsTheExtraPlayEvenWhenDeclined(): void
    {
        $state = $this->boardState(hands: [1 => [38, 106]]);
        $state->startTurn(1);

        $this->plays->playMood($state, 1, 38, new PlayerChoices([]));

        self::assertSame(1, $state->playsRemaining());
        $this->plays->playMood($state, 1, 106, new PlayerChoices([]));
        self::assertTrue($state->isInPlay(106));
    }

    public function testParanoiaBottomsARandomCardFromTheTargetsHandAndDraws(): void
    {
        $state = $this->boardState(hands: [1 => [71], 2 => [9, 3]], deck: [106]);
        $state->startTurn(1);

        $this->plays->playMood($state, 1, 71, new PlayerChoices(['target_player_id' => 2]));

        self::assertCount(1, $state->hand(2));
        self::assertTrue($state->isInHand(1, 106));
        self::assertCount(1, $state->deck());
    }

    public function testParanoiaRejectsATargetWithAnEmptyHand(): void
    {
        $state = $this->boardState(hands: [1 => [71], 2 => []]);
        $state->startTurn(1);

        $this->expectException(InvalidChoiceException::class);
        $this->plays->playMood($state, 1, 71, new PlayerChoices(['target_player_id' => 2]));
    }

    public function testParanoiaDoesNothingWhenDeclined(): void
    {
        $state = $this->boardState(hands: [1 => [71]]);
        $state->startTurn(1);

        $this->plays->playMood($state, 1, 71, new PlayerChoices([]));

        self::assertTrue($state->isInPlay(71));
    }

    public function testSuspicionPausesForEachChosenPlayersOwnChoiceThenDiscardsThem(): void
    {
        $state = $this->boardState(hands: [1 => [78], 2 => [9, 3], 3 => [106]]);
        $state->startTurn(1);

        $choices = new PlayerChoices(['player_ids' => [2, 3]]);
        $result = $this->plays->playMood($state, 1, 78, $choices);

        self::assertTrue($result->isPending);
        self::assertCount(2, $result->pendingDecisions);
        self::assertSame('discarded_card_id_2', $result->pendingDecisions[0]->key);
        self::assertSame(2, $result->pendingDecisions[0]->targetPlayerId);
        self::assertSame('discarded_card_id_3', $result->pendingDecisions[1]->key);
        self::assertSame(3, $result->pendingDecisions[1]->targetPlayerId);
        self::assertCount(2, $state->hand(2)); // not discarded yet
        self::assertCount(1, $state->hand(3));

        $this->plays->resolvePendingDecisions(
            $state, 78, 1, $choices, $choices, 0,
            [
                'discarded_card_id_2' => new PlayerChoices(['discarded_card_id_2' => 9]),
                'discarded_card_id_3' => new PlayerChoices(['discarded_card_id_3' => 106]),
            ],
        );

        self::assertSame([3], $state->hand(2));
        self::assertCount(0, $state->hand(3));
        self::assertCount(2, $state->discardPile());
    }

    public function testSuspicionRejectsAChosenPlayerWithAnEmptyHand(): void
    {
        $state = $this->boardState(hands: [1 => [78], 2 => []]);
        $state->startTurn(1);

        $this->expectException(InvalidChoiceException::class);
        $this->plays->playMood($state, 1, 78, new PlayerChoices(['player_ids' => [2]]));
    }

    public function testAltruismBoostsValueAndDistributesOneDiscardedCardPerPlayerStartingNext(): void
    {
        $state = $this->boardState(hands: [1 => [1]], discard: [9, 55, 106]); // exactly 3 cards for 3 players
        $state->startTurn(1);

        $this->plays->playMood($state, 1, 1, new PlayerChoices([]));

        self::assertSame(7, $state->valueOf(1));
        self::assertCount(1, $state->hand(1));
        self::assertCount(1, $state->hand(2));
        self::assertCount(1, $state->hand(3));
        self::assertSame([], $state->discardPile());
    }

    public function testAltruismDoesNothingWhenTheDiscardPileIsEmpty(): void
    {
        $state = $this->boardState(hands: [1 => [1]]);
        $state->startTurn(1);

        $this->plays->playMood($state, 1, 1, new PlayerChoices([]));

        self::assertSame(3, $state->valueOf(1));
        self::assertSame([], $state->discardPile());
    }

    public function testAltruismShufflesTheRemainderToTheBottomOfTheDeck(): void
    {
        $state = $this->boardState(hands: [1 => [1]], discard: [9, 55, 106, 3, 7]); // 5 cards, 3 players
        $state->startTurn(1);

        $this->plays->playMood($state, 1, 1, new PlayerChoices([]));

        self::assertSame([], $state->discardPile());
        self::assertCount(2, $state->deck());
        self::assertCount(1, $state->hand(1));
        self::assertCount(1, $state->hand(2));
        self::assertCount(1, $state->hand(3));
    }

    public function testCuriosityBoostsValueWhenTheRevealedCardSharesAColor(): void
    {
        $state = $this->boardState(hands: [1 => [33], 2 => [9], 3 => [3]]); // Discipline (white) revealed, Charity (white) in play
        $state->moveHandToInPlay(3, 3);
        $state->startTurn(1);

        $this->plays->playMood($state, 1, 33, new PlayerChoices(['target_player_id' => 2]));

        self::assertSame(6, $state->valueOf(33));
    }

    public function testCuriosityDoesNotBoostValueWhenNoColorMatches(): void
    {
        $state = $this->boardState(hands: [1 => [33], 2 => [9]]); // Discipline (white); only Curiosity (blue) is in play
        $state->startTurn(1);

        $this->plays->playMood($state, 1, 33, new PlayerChoices(['target_player_id' => 2]));

        self::assertSame(3, $state->valueOf(33));
    }

    public function testCuriosityRejectsATargetWithAnEmptyHand(): void
    {
        $state = $this->boardState(hands: [1 => [33], 2 => []]);
        $state->startTurn(1);

        $this->expectException(InvalidChoiceException::class);
        $this->plays->playMood($state, 1, 33, new PlayerChoices(['target_player_id' => 2]));
    }

    public function testCuriosityDoesNothingWhenDeclined(): void
    {
        $state = $this->boardState(hands: [1 => [33]]);
        $state->startTurn(1);

        $this->plays->playMood($state, 1, 33, new PlayerChoices([]));

        self::assertSame(3, $state->valueOf(33));
    }

    public function testCondescensionBoostsValueWhenACardIsGivenAway(): void
    {
        $state = $this->boardState(hands: [1 => [58, 9], 2 => []]);
        $state->startTurn(1);

        $this->plays->playMood($state, 1, 58, new PlayerChoices(['give_card_id' => 9, 'recipient_player_id' => 2]));

        self::assertSame(6, $state->valueOf(58));
        self::assertTrue($state->isInHand(2, 9));
        self::assertFalse($state->isInHand(1, 9));
    }

    public function testCondescensionDoesNothingWhenDeclined(): void
    {
        $state = $this->boardState(hands: [1 => [58]]);
        $state->startTurn(1);

        $this->plays->playMood($state, 1, 58, new PlayerChoices([]));

        self::assertSame(3, $state->valueOf(58));
    }

    public function testCynicismBoostsValueWhenADiscardCardIsGivenToAnOpponent(): void
    {
        $state = $this->boardState(hands: [1 => [62]], discard: [9]);
        $state->startTurn(1);

        $this->plays->playMood($state, 1, 62, new PlayerChoices(['discard_card_id' => 9, 'recipient_player_id' => 2]));

        self::assertSame(6, $state->valueOf(62));
        self::assertTrue($state->isInHand(2, 9));
    }

    public function testCynicismDoesNothingWhenDeclined(): void
    {
        $state = $this->boardState(hands: [1 => [62]]);
        $state->startTurn(1);

        $this->plays->playMood($state, 1, 62, new PlayerChoices([]));

        self::assertSame(3, $state->valueOf(62));
    }

    public function testInfatuationBoostsValueWhenTwoOtherMoodsAreDiscarded(): void
    {
        $state = $this->boardState(hands: [1 => [95, 9, 3]]);
        $state->moveHandToInPlay(1, 9);
        $state->moveHandToInPlay(1, 3);
        $state->startTurn(1);

        $this->plays->playMood($state, 1, 95, new PlayerChoices(['discard_mood_ids' => [9, 3]]));

        self::assertSame(9, $state->valueOf(95));
        self::assertFalse($state->isInPlay(9));
        self::assertFalse($state->isInPlay(3));
    }

    public function testInfatuationDoesNothingWhenDeclined(): void
    {
        $state = $this->boardState(hands: [1 => [95]]);
        $state->startTurn(1);

        $this->plays->playMood($state, 1, 95, new PlayerChoices([]));

        self::assertSame(3, $state->valueOf(95));
    }

    public function testHostilityDiscardsQualifyingCostAndUpToTwoLowValueMoods(): void
    {
        $state = $this->boardState(hands: [1 => [94, 57], 2 => [3]]);
        $state->moveHandToInPlay(1, 57); // Bitterness, black -- qualifies the cost
        $state->moveHandToInPlay(2, 3); // Charity, value 1
        $state->startTurn(1);

        $this->plays->playMood($state, 1, 94, new PlayerChoices(['discard_mood_id' => 57, 'target_mood_ids' => [3]]));

        self::assertFalse($state->isInPlay(57));
        self::assertFalse($state->isInPlay(3));
        self::assertTrue($state->isInPlay(94));
    }

    public function testHostilityCanTargetItselfInTheSecondStage(): void
    {
        // Unlike Worry, Hostility's card text has no "other than this one"
        // exclusion, and its own flat value (3) qualifies.
        $state = $this->boardState(hands: [1 => [94, 57]]);
        $state->moveHandToInPlay(1, 57);
        $state->startTurn(1);

        $this->plays->playMood($state, 1, 94, new PlayerChoices(['discard_mood_id' => 57, 'target_mood_ids' => [94]]));

        self::assertFalse($state->isInPlay(94));
    }

    public function testHostilityRejectsANonQualifyingCostColor(): void
    {
        $state = $this->boardState(hands: [1 => [94, 3]]); // Charity is white, doesn't qualify
        $state->moveHandToInPlay(1, 3);
        $state->startTurn(1);

        $this->expectException(InvalidChoiceException::class);
        $this->plays->playMood($state, 1, 94, new PlayerChoices(['discard_mood_id' => 3]));
    }

    public function testMalicePausesForTheTargetsOwnTwoMoodChoiceThenDiscardsMatchingColors(): void
    {
        $state = $this->boardState(hands: [1 => [68], 2 => [9, 3], 3 => [8]]);
        $state->moveHandToInPlay(2, 9); // Discipline, white
        $state->moveHandToInPlay(2, 3); // Charity, white
        $state->moveHandToInPlay(3, 8); // Dignity, white
        $state->startTurn(1);

        $choices = new PlayerChoices(['target_player_id' => 2]);
        $result = $this->plays->playMood($state, 1, 68, $choices);

        self::assertTrue($result->isPending);
        self::assertCount(1, $result->pendingDecisions);
        $decision = $result->pendingDecisions[0];
        self::assertSame('chosen_mood_ids', $decision->key);
        self::assertSame(2, $decision->targetPlayerId);
        self::assertSame('malice_choose_moods', $decision->decisionType);
        self::assertTrue($decision->field['multi']);
        self::assertTrue($state->isInPlay(9)); // not discarded yet
        self::assertTrue($state->isInPlay(3));

        $this->plays->resolvePendingDecisions(
            $state, 68, 1, $choices, $choices, 0,
            ['chosen_mood_ids' => new PlayerChoices(['chosen_mood_ids' => [9, 3]])],
        );

        self::assertFalse($state->isInPlay(9));
        self::assertFalse($state->isInPlay(3));
        self::assertFalse($state->isInPlay(8)); // shares white with the two chosen moods
    }

    public function testMaliceRejectsATargetWithFewerThanTwoMoods(): void
    {
        $state = $this->boardState(hands: [1 => [68], 2 => [9]]);
        $state->moveHandToInPlay(2, 9);
        $state->startTurn(1);

        $this->expectException(InvalidChoiceException::class);
        $this->plays->playMood($state, 1, 68, new PlayerChoices(['target_player_id' => 2]));
    }

    public function testMaliceDoesNothingWhenDeclined(): void
    {
        $state = $this->boardState(hands: [1 => [68]]);
        $state->startTurn(1);

        $result = $this->plays->playMood($state, 1, 68, new PlayerChoices([]));

        self::assertFalse($result->isPending);
        self::assertTrue($state->isInPlay(68));
    }

    public function testFicklenessReturnsMoodsOfTheMostCommonColorToHand(): void
    {
        $state = $this->boardState(hands: [1 => [39], 2 => [9, 3], 3 => [56]]);
        $state->moveHandToInPlay(2, 9); // Discipline, white
        $state->moveHandToInPlay(2, 3); // Charity, white
        $state->moveHandToInPlay(3, 56); // Betrayal, black
        $state->startTurn(1);

        $this->plays->playMood($state, 1, 39, new PlayerChoices([]));

        self::assertTrue($state->isInHand(2, 9));
        self::assertTrue($state->isInHand(2, 3));
        self::assertTrue($state->isInPlay(56)); // black wasn't the most common color
    }

    public function testHesitationReturnsASingleChosenRedOrGreenMoodToHand(): void
    {
        $state = $this->boardState(hands: [1 => [41], 2 => [106]]); // Zeal, red
        $state->moveHandToInPlay(2, 106);
        $state->startTurn(1);

        $this->plays->playMood($state, 1, 41, new PlayerChoices(['mode' => 'single', 'target_mood_id' => 106]));

        self::assertTrue($state->isInHand(2, 106));
    }

    public function testHesitationReturnsAllRedAndGreenMoodsInAllMode(): void
    {
        $state = $this->boardState(hands: [1 => [41], 2 => [106], 3 => [118]]); // Zeal (red), Fascination (green)
        $state->moveHandToInPlay(2, 106);
        $state->moveHandToInPlay(3, 118);
        $state->startTurn(1);

        $this->plays->playMood($state, 1, 41, new PlayerChoices(['mode' => 'all']));

        self::assertTrue($state->isInHand(2, 106));
        self::assertTrue($state->isInHand(3, 118));
    }

    public function testNostalgiaReturnsDiscardCardAndGrantsAnUnconditionalExtraPlay(): void
    {
        $state = $this->boardState(hands: [1 => [128, 106]], discard: [9]);
        $state->startTurn(1);

        $this->plays->playMood($state, 1, 128, new PlayerChoices(['discard_card_id' => 9]));

        self::assertTrue($state->isInHand(1, 9));
        self::assertSame(1, $state->playsRemaining());

        $this->plays->playMood($state, 1, 106, new PlayerChoices([]));
        self::assertTrue($state->isInPlay(106));
    }

    public function testNostalgiaGrantsTheExtraPlayEvenWhenDeclined(): void
    {
        $state = $this->boardState(hands: [1 => [128, 106]]);
        $state->startTurn(1);

        $this->plays->playMood($state, 1, 128, new PlayerChoices([]));

        self::assertSame(1, $state->playsRemaining());
        $this->plays->playMood($state, 1, 106, new PlayerChoices([]));
        self::assertTrue($state->isInPlay(106));
    }

    public function testHarmonyGrantsAnExtraPlayUsableOnlyFromTheDiscardPile(): void
    {
        $state = $this->boardState(hands: [1 => [123, 9]], discard: [106]);
        $state->startTurn(1);

        $this->plays->playMood($state, 1, 123, new PlayerChoices([]));
        self::assertSame(1, $state->playsRemaining());

        $this->plays->playMood($state, 1, 106, new PlayerChoices([]));

        self::assertTrue($state->isInPlay(106));
        self::assertFalse($state->isInDiscardPile(106));
        self::assertSame(0, $state->playsRemaining());
    }

    public function testHarmonyGrantCannotBeUsedOnAHandCard(): void
    {
        $state = $this->boardState(hands: [1 => [123, 9]]);
        $state->startTurn(1);
        $this->plays->playMood($state, 1, 123, new PlayerChoices([]));

        $this->expectException(IllegalPlayException::class);
        $this->plays->playMood($state, 1, 9, new PlayerChoices([]));
    }

    public function testGriefGrantsTwoExtraPlaysUsableOnlyFromTheDiscardPile(): void
    {
        $state = $this->boardState(hands: [1 => [65]], discard: [9, 106]);
        $state->startTurn(1);

        $this->plays->playMood($state, 1, 65, new PlayerChoices([]));
        self::assertSame(2, $state->playsRemaining());

        $this->plays->playMood($state, 1, 9, new PlayerChoices([]));
        self::assertSame(1, $state->playsRemaining());

        $this->plays->playMood($state, 1, 106, new PlayerChoices([]));
        self::assertSame(0, $state->playsRemaining());
    }

    public function testAngstDiscardsQualifyingMoodAndGrantsADiscardSourcedPlay(): void
    {
        $state = $this->boardState(hands: [1 => [54, 42]], discard: [106]);
        $state->moveHandToInPlay(1, 42); // Imagination, blue -- qualifies
        $state->startTurn(1);

        $this->plays->playMood($state, 1, 54, new PlayerChoices(['discard_mood_id' => 42]));

        self::assertFalse($state->isInPlay(42));
        self::assertSame(1, $state->playsRemaining());

        $this->plays->playMood($state, 1, 106, new PlayerChoices([]));
        self::assertTrue($state->isInPlay(106));
    }

    public function testAngstRejectsANonQualifyingCostColor(): void
    {
        $state = $this->boardState(hands: [1 => [54, 9]]);
        $state->moveHandToInPlay(1, 9); // Discipline, white -- doesn't qualify
        $state->startTurn(1);

        $this->expectException(InvalidChoiceException::class);
        $this->plays->playMood($state, 1, 54, new PlayerChoices(['discard_mood_id' => 9]));
    }

    public function testAngstDoesNothingWhenDeclined(): void
    {
        $state = $this->boardState(hands: [1 => [54]]);
        $state->startTurn(1);

        $this->plays->playMood($state, 1, 54, new PlayerChoices([]));

        self::assertSame(0, $state->playsRemaining());
    }

    public function testHonorSetsTheFirstPlayerOverride(): void
    {
        $state = $this->boardState(hands: [1 => [15]]);
        $state->startTurn(1);

        $this->plays->playMood($state, 1, 15, new PlayerChoices(['target_player_id' => 2]));

        self::assertSame(2, $state->firstPlayerOverride());
    }

    public function testHonorRejectsAnInvalidPlayer(): void
    {
        $state = $this->boardState(hands: [1 => [15]]);
        $state->startTurn(1);

        $this->expectException(InvalidChoiceException::class);
        $this->plays->playMood($state, 1, 15, new PlayerChoices(['target_player_id' => 99]));
    }

    public function testAvoidanceGivesEachPlayersOnlyMoodToTheirRightNeighbor(): void
    {
        $state = $this->boardState(hands: [1 => [29], 2 => [9], 3 => [106]]);
        $state->moveHandToInPlay(2, 9);
        $state->moveHandToInPlay(3, 106);
        $state->startTurn(1);

        $this->plays->playMood($state, 1, 29, new PlayerChoices(['direction' => 'right']));

        self::assertSame(2, $state->ownerOf(29)); // player 1's only mood -- Avoidance itself
        self::assertSame(3, $state->ownerOf(9));
        self::assertSame(1, $state->ownerOf(106)); // wraps around
    }

    public function testAvoidanceGivesEachPlayersOnlyMoodToTheirLeftNeighbor(): void
    {
        $state = $this->boardState(hands: [1 => [29], 2 => [9], 3 => [106]]);
        $state->moveHandToInPlay(2, 9);
        $state->moveHandToInPlay(3, 106);
        $state->startTurn(1);

        $this->plays->playMood($state, 1, 29, new PlayerChoices(['direction' => 'left']));

        self::assertSame(3, $state->ownerOf(29));
        self::assertSame(1, $state->ownerOf(9));
        self::assertSame(2, $state->ownerOf(106));
    }

    public function testAvoidanceRejectsAnInvalidDirection(): void
    {
        $state = $this->boardState(hands: [1 => [29]]);
        $state->startTurn(1);

        $this->expectException(InvalidChoiceException::class);
        $this->plays->playMood($state, 1, 29, new PlayerChoices(['direction' => 'up']));
    }

    public function testConfusionGivesEachPlayersOnlyHandCardToTheirRightNeighbor(): void
    {
        $state = $this->boardState(hands: [1 => [31, 3], 2 => [9], 3 => [106]]);
        $state->startTurn(1);

        $this->plays->playMood($state, 1, 31, new PlayerChoices(['direction' => 'right']));

        self::assertTrue($state->isInHand(2, 3));
        self::assertTrue($state->isInHand(3, 9));
        self::assertTrue($state->isInHand(1, 106));
    }

    public function testRationalizationRefreshPutsHandOnBottomOfDeckAndDrawsThatMany(): void
    {
        $state = $this->boardState(hands: [1 => [49, 9, 3]], deck: [106, 42]);
        $state->startTurn(1);

        $this->plays->playMood($state, 1, 49, new PlayerChoices(['mode' => 'refresh']));

        self::assertTrue($state->isInHand(1, 106));
        self::assertTrue($state->isInHand(1, 42));
        self::assertSame([9, 3], $state->deck());
    }

    public function testRationalizationRotateSwapsWholeHands(): void
    {
        $state = $this->boardState(hands: [1 => [49, 3], 2 => [9], 3 => [106]]);
        $state->startTurn(1);

        $this->plays->playMood($state, 1, 49, new PlayerChoices(['mode' => 'rotate', 'direction' => 'right']));

        self::assertTrue($state->isInHand(2, 3));
        self::assertTrue($state->isInHand(3, 9));
        self::assertTrue($state->isInHand(1, 106));
    }

    public function testRationalizationRejectsAnInvalidMode(): void
    {
        $state = $this->boardState(hands: [1 => [49]]);
        $state->startTurn(1);

        $this->expectException(InvalidChoiceException::class);
        $this->plays->playMood($state, 1, 49, new PlayerChoices(['mode' => 'bogus']));
    }

    public function testInstabilityPausesForTheOpponentsOwnChoiceThenGivesBackOneOfYourOwn(): void
    {
        $state = $this->boardState(hands: [1 => [96, 9], 2 => [3, 7]]);
        $state->moveHandToInPlay(1, 9);
        $state->moveHandToInPlay(2, 3);
        $state->moveHandToInPlay(2, 7);
        $state->startTurn(1);

        $choices = new PlayerChoices(['candidate_mood_ids' => [3, 7], 'given_mood_id' => 9]);
        $result = $this->plays->playMood($state, 1, 96, $choices);

        self::assertTrue($result->isPending);
        self::assertCount(1, $result->pendingDecisions);
        $decision = $result->pendingDecisions[0];
        self::assertSame('taken_mood_id', $decision->key);
        self::assertSame(2, $decision->targetPlayerId);
        self::assertSame('instability_choose_mood', $decision->decisionType);
        self::assertSame([3, 7], $decision->field['candidate_card_ids']);
        self::assertSame(2, $state->ownerOf(3)); // not taken yet
        self::assertSame(2, $state->ownerOf(7));

        $this->plays->resolvePendingDecisions(
            $state, 96, 1, $choices, $choices, 0,
            ['taken_mood_id' => new PlayerChoices(['taken_mood_id' => 7])],
        );

        self::assertSame(1, $state->ownerOf(7));
        self::assertSame(2, $state->ownerOf(3)); // the other candidate is untouched
        self::assertSame(2, $state->ownerOf(9));
    }

    public function testInstabilityRejectsCandidatesFromDifferentPlayers(): void
    {
        $state = $this->boardState(hands: [1 => [96], 2 => [3], 3 => [7]]);
        $state->moveHandToInPlay(2, 3);
        $state->moveHandToInPlay(3, 7);
        $state->startTurn(1);

        $this->expectException(InvalidChoiceException::class);
        $this->plays->playMood($state, 1, 96, new PlayerChoices(['candidate_mood_ids' => [3, 7]]));
    }

    public function testInstabilityDoesNothingWhenDeclined(): void
    {
        $state = $this->boardState(hands: [1 => [96]]);
        $state->startTurn(1);

        $result = $this->plays->playMood($state, 1, 96, new PlayerChoices([]));

        self::assertFalse($result->isPending);
        self::assertTrue($state->isInPlay(96));
    }

    public function testBashfulnessTagsItselfForAfterScoringIfWon(): void
    {
        $state = $this->boardState(hands: [1 => [30]]);
        $state->startTurn(1);

        $this->plays->playMood($state, 1, 30, new PlayerChoices([]));

        self::assertSame(['action' => 'bottom_and_draw', 'condition' => 'if_won'], $state->effectState(30, 'afterScoring'));
    }

    public function testBetrayalGivesAMoodAwayAndTagsItToReturn(): void
    {
        $state = $this->boardState(hands: [1 => [56, 3]]); // Betrayal, Charity
        $state->moveHandToInPlay(1, 3);
        $state->startTurn(1);

        $this->plays->playMood($state, 1, 56, new PlayerChoices(['target_mood_id' => 3, 'recipient_player_id' => 2]));

        self::assertSame(2, $state->ownerOf(3));
        self::assertSame(1, $state->effectState(3, 'returnsToOwnerAfterScoring'));
    }

    public function testBetrayalRejectsATargetNotOwnedByYou(): void
    {
        $state = $this->boardState(hands: [1 => [56], 2 => [3]]);
        $state->moveHandToInPlay(2, 3);
        $state->startTurn(1);

        $this->expectException(InvalidChoiceException::class);
        $this->plays->playMood($state, 1, 56, new PlayerChoices(['target_mood_id' => 3, 'recipient_player_id' => 2]));
    }

    public function testBetrayalRejectsGivingToYourself(): void
    {
        $state = $this->boardState(hands: [1 => [56, 3]]);
        $state->moveHandToInPlay(1, 3);
        $state->startTurn(1);

        $this->expectException(InvalidChoiceException::class);
        $this->plays->playMood($state, 1, 56, new PlayerChoices(['target_mood_id' => 3, 'recipient_player_id' => 1]));
    }

    public function testSneakinessTagsAnOpponentForAScoreSwap(): void
    {
        $state = $this->boardState(hands: [1 => [51]]);
        $state->startTurn(1);

        $this->plays->playMood($state, 1, 51, new PlayerChoices(['opponent_player_id' => 2]));

        self::assertSame(2, $state->effectState(51, 'swapScoreWithPlayerId'));
    }

    public function testSneakinessRejectsTargetingYourself(): void
    {
        $state = $this->boardState(hands: [1 => [51]]);
        $state->startTurn(1);

        $this->expectException(InvalidChoiceException::class);
        $this->plays->playMood($state, 1, 51, new PlayerChoices(['opponent_player_id' => 1]));
    }

    public function testAweTagsFirstPlayerOverrideAndSkipScoring(): void
    {
        $state = $this->boardState(hands: [1 => [107]]);
        $state->startTurn(1);

        $this->plays->playMood($state, 1, 107, new PlayerChoices(['target_player_id' => 3]));

        self::assertSame(3, $state->firstPlayerOverride());
        self::assertTrue($state->effectState(107, 'skipScoringThisRound'));
    }

    public function testAweRejectsAnInvalidPlayer(): void
    {
        $state = $this->boardState(hands: [1 => [107]]);
        $state->startTurn(1);

        $this->expectException(InvalidChoiceException::class);
        $this->plays->playMood($state, 1, 107, new PlayerChoices(['target_player_id' => 99]));
    }

    public function testRecklessnessTagsItselfUnconditionallyForAfterScoring(): void
    {
        $state = $this->boardState(hands: [1 => [100]]);
        $state->startTurn(1);

        $this->plays->playMood($state, 1, 100, new PlayerChoices([]));

        self::assertSame(['action' => 'bottom_and_draw', 'condition' => 'always'], $state->effectState(100, 'afterScoring'));
    }

    public function testRecklessnessOptionallyTakesAnOpponentsMoodAndTagsItToReturn(): void
    {
        $state = $this->boardState(hands: [1 => [100], 2 => [3]]);
        $state->moveHandToInPlay(2, 3);
        $state->startTurn(1);

        $this->plays->playMood($state, 1, 100, new PlayerChoices(['target_mood_id' => 3]));

        self::assertSame(1, $state->ownerOf(3));
        self::assertSame(2, $state->effectState(3, 'returnsToOwnerAfterScoring'));
    }

    public function testRecklessnessRejectsTakingYourOwnMood(): void
    {
        $state = $this->boardState(hands: [1 => [100, 3]]);
        $state->moveHandToInPlay(1, 3);
        $state->startTurn(1);

        $this->expectException(InvalidChoiceException::class);
        $this->plays->playMood($state, 1, 100, new PlayerChoices(['target_mood_id' => 3]));
    }

    public function testRecklessnessDoesNothingExtraWhenDeclined(): void
    {
        $state = $this->boardState(hands: [1 => [100]]);
        $state->startTurn(1);

        $this->plays->playMood($state, 1, 100, new PlayerChoices([]));

        self::assertTrue($state->isInPlay(100));
    }

    public function testGluttonyGrantsAnExtraPlayTaggedToDiscardAfterScoring(): void
    {
        $state = $this->boardState(hands: [1 => [93, 5]]); // Gluttony, Complacency (no abilities)
        $state->startTurn(1);

        $this->plays->playMood($state, 1, 93, new PlayerChoices([]));
        self::assertSame(1, $state->playsRemaining());

        $this->plays->playMood($state, 1, 5, new PlayerChoices([]));

        self::assertTrue($state->isInPlay(5));
        self::assertSame(['action' => 'discard', 'condition' => 'always'], $state->effectState(5, 'afterScoring'));
    }

    public function testGluttonyLeavesNoTagWhenTheExtraPlayGoesUnused(): void
    {
        $state = $this->boardState(hands: [1 => [93]]);
        $state->startTurn(1);

        $this->plays->playMood($state, 1, 93, new PlayerChoices([]));

        self::assertNull($state->effectState(93, 'afterScoring'));
    }

    public function testInsecurityGrantsAnExtraPlayTaggedToReturnToHandAfterScoring(): void
    {
        $state = $this->boardState(hands: [1 => [45, 5]]); // Insecurity, Complacency (no abilities)
        $state->startTurn(1);

        $this->plays->playMood($state, 1, 45, new PlayerChoices([]));
        $this->plays->playMood($state, 1, 5, new PlayerChoices([]));

        self::assertSame(['action' => 'return_to_hand', 'condition' => 'always'], $state->effectState(5, 'afterScoring'));
    }

    public function testPatienceValueIs1WhenPlayedThisRound(): void
    {
        $state = $this->boardState(hands: [1 => [21]]);
        $state->startRound(1, 5);
        $state->startTurn(1);

        $this->plays->playMood($state, 1, 21, new PlayerChoices([]));

        self::assertSame(1, $state->valueOf(21));
    }

    public function testPatienceValueIsBaseOnceTheRoundHasMovedOn(): void
    {
        $state = $this->boardState(hands: [1 => [21]]);
        $state->startRound(1, 5);
        $state->startTurn(1);
        $this->plays->playMood($state, 1, 21, new PlayerChoices([]));

        $state->startRound(2, 6);

        self::assertSame(5, $state->valueOf(21));
    }

    public function testGleeValueIs6WhenPlayedThisRound(): void
    {
        $state = $this->boardState(hands: [1 => [92]]);
        $state->startRound(1, 5);
        $state->startTurn(1);

        $this->plays->playMood($state, 1, 92, new PlayerChoices([]));

        self::assertSame(6, $state->valueOf(92));
    }

    public function testGleeValueIsBaseOnceTheRoundHasMovedOn(): void
    {
        $state = $this->boardState(hands: [1 => [92]]);
        $state->startRound(1, 5);
        $state->startTurn(1);
        $this->plays->playMood($state, 1, 92, new PlayerChoices([]));

        $state->startRound(2, 6);

        self::assertSame(0, $state->valueOf(92));
    }

    public function testPrideGrantsExtraPlaysToMatchTheChosenPlayersMoodCount(): void
    {
        $state = $this->boardState(hands: [1 => [22, 4, 9], 2 => [5, 32, 55]]);
        $state->moveHandToInPlay(2, 5);
        $state->moveHandToInPlay(2, 32);
        $state->moveHandToInPlay(2, 55);
        $state->startTurn(1);

        // Pride itself already counts, so player 1 has 1 mood vs player 2's
        // 3 -- a gap of 2 extra plays.
        $this->plays->playMood($state, 1, 22, new PlayerChoices(['target_player_id' => 2]));
        self::assertSame(2, $state->playsRemaining());

        $this->plays->playMood($state, 1, 4, new PlayerChoices([]));
        $this->plays->playMood($state, 1, 9, new PlayerChoices([]));

        self::assertTrue($state->isInPlay(4));
        self::assertTrue($state->isInPlay(9));
        self::assertSame(0, $state->playsRemaining());
    }

    public function testPrideRejectsAPlayerWithoutMoreMoods(): void
    {
        $state = $this->boardState(hands: [1 => [22], 2 => [5]]);
        $state->moveHandToInPlay(2, 5);
        $state->startTurn(1);

        $this->expectException(InvalidChoiceException::class);
        $this->plays->playMood($state, 1, 22, new PlayerChoices(['target_player_id' => 2]));
    }

    public function testPrideDoesNothingWhenDeclined(): void
    {
        $state = $this->boardState(hands: [1 => [22]]);
        $state->startTurn(1);

        $this->plays->playMood($state, 1, 22, new PlayerChoices([]));

        self::assertTrue($state->isInPlay(22));
        self::assertSame(0, $state->playsRemaining());
    }

    public function testCorruptionCyclesDiscardCardsAndDraws(): void
    {
        $state = $this->boardState(hands: [1 => [60]], deck: [7, 9], discard: [3, 8]);
        $state->startTurn(1);

        $this->plays->playMood($state, 1, 60, new PlayerChoices(['mode' => 'cycle', 'discard_card_ids' => [3, 8]]));

        self::assertSame([3, 8], $state->deck());
        self::assertSame([], $state->discardPile());
        self::assertContains(7, $state->hand(1));
        self::assertContains(9, $state->hand(1));
    }

    public function testCorruptionRejectsMoreThanTwoDiscardTargets(): void
    {
        $state = $this->boardState(hands: [1 => [60]], discard: [3, 8, 9]);
        $state->startTurn(1);

        $this->expectException(InvalidChoiceException::class);
        $this->plays->playMood($state, 1, 60, new PlayerChoices(['mode' => 'cycle', 'discard_card_ids' => [3, 8, 9]]));
    }

    public function testCorruptionTagsTheRoundForAnExtraWin(): void
    {
        $state = $this->boardState(hands: [1 => [60]]);
        $state->startTurn(1);

        $this->plays->playMood($state, 1, 60, new PlayerChoices(['mode' => 'double_win']));

        self::assertTrue($state->effectState(60, 'awardsExtraWin'));
    }

    public function testCorruptionRejectsAnInvalidMode(): void
    {
        $state = $this->boardState(hands: [1 => [60]]);
        $state->startTurn(1);

        $this->expectException(InvalidChoiceException::class);
        $this->plays->playMood($state, 1, 60, new PlayerChoices(['mode' => 'bogus']));
    }

    public function testCorruptionDoesNothingWhenDeclined(): void
    {
        $state = $this->boardState(hands: [1 => [60]]);
        $state->startTurn(1);

        $this->plays->playMood($state, 1, 60, new PlayerChoices([]));

        self::assertTrue($state->isInPlay(60));
        self::assertNull($state->effectState(60, 'awardsExtraWin'));
    }

    public function testMelancholyAllowsPlayingFromTheDiscardPileAsANormalPlay(): void
    {
        $state = $this->boardState(hands: [1 => [69]], discard: [5]);
        $state->moveHandToInPlay(1, 69);
        $state->startTurn(1);

        $this->plays->playMood($state, 1, 5, new PlayerChoices([]));

        self::assertTrue($state->isInPlay(5));
        self::assertSame(0, $state->playsRemaining());
    }

    public function testCannotPlayADiscardPileCardWithoutMelancholy(): void
    {
        $state = $this->boardState(hands: [1 => []], discard: [5]);
        $state->startTurn(1);

        $this->expectException(IllegalPlayException::class);
        $this->plays->playMood($state, 1, 5, new PlayerChoices([]));
    }

    public function testDoubtBansRevealedColorsDuringTheFollowingRoundOnly(): void
    {
        $state = $this->boardState(hands: [1 => [36, 8]], deck: [55, 106]); // Doubt, Dignity (white)
        $state->startRound(1, 3);
        $state->startTurn(1);

        $this->plays->playMood($state, 1, 36, new PlayerChoices(['reveal_card_ids' => [8]]));

        self::assertSame([], $state->bannedColorsThisRound()); // still round 3 -- not active yet

        $state->startRound(2, 4);
        self::assertSame(['white'], $state->bannedColorsThisRound());

        $state->startRound(1, 5);
        self::assertSame([], $state->bannedColorsThisRound()); // expired
    }

    public function testDoubtBlocksPlayingABannedColorDuringTheFollowingRound(): void
    {
        $state = $this->boardState(hands: [1 => [36, 8], 2 => [7]]); // Dignity/Courage are both white
        $state->startRound(1, 3);
        $state->startTurn(1);
        $this->plays->playMood($state, 1, 36, new PlayerChoices(['reveal_card_ids' => [8]]));

        $state->startRound(2, 4);
        $state->startTurn(2);

        $this->expectException(IllegalPlayException::class);
        $this->plays->playMood($state, 2, 7, new PlayerChoices([]));
    }

    public function testDoubtRejectsARevealedCardNotInHand(): void
    {
        $state = $this->boardState(hands: [1 => [36]], discard: [8]);
        $state->startTurn(1);

        $this->expectException(InvalidChoiceException::class);
        $this->plays->playMood($state, 1, 36, new PlayerChoices(['reveal_card_ids' => [8]]));
    }

    public function testDoubtDoesNothingWhenDeclined(): void
    {
        $state = $this->boardState(hands: [1 => [36]]);
        $state->startRound(1, 3);
        $state->startTurn(1);

        $this->plays->playMood($state, 1, 36, new PlayerChoices([]));

        self::assertTrue($state->isInPlay(36));
        self::assertSame([], $state->bannedColorsThisRound());
    }

    public function testHopeGrantsAnExtraPlayTheTurnItsPlayed(): void
    {
        $state = $this->boardState(hands: [1 => [124, 5]]); // Hope, Complacency (no abilities)
        $state->startTurn(1);

        $this->plays->playMood($state, 1, 124, new PlayerChoices([]));
        self::assertSame(1, $state->playsRemaining());

        $this->plays->playMood($state, 1, 5, new PlayerChoices([]));

        self::assertTrue($state->isInPlay(5));
        self::assertSame(0, $state->playsRemaining());
    }

    public function testGraceGrantsADiscardSourcedColorMatchingPlayTheTurnItsPlayed(): void
    {
        $state = $this->boardState(hands: [1 => [121]], discard: [110]); // Grace (green), Cheer (green) in discard
        $state->startTurn(1);

        $this->plays->playMood($state, 1, 121, new PlayerChoices([]));
        self::assertSame(1, $state->playsRemaining());

        $this->plays->playMood($state, 1, 110, new PlayerChoices([]));

        self::assertTrue($state->isInPlay(110));
        self::assertSame(0, $state->playsRemaining());
    }

    public function testGraceDoesNotGrantAHandSourcedPlay(): void
    {
        $state = $this->boardState(hands: [1 => [121, 5]]); // Grace, Complacency in hand (not discard)
        $state->startTurn(1);
        $this->plays->playMood($state, 1, 121, new PlayerChoices([]));

        $this->expectException(IllegalPlayException::class);
        $this->plays->playMood($state, 1, 5, new PlayerChoices([]));
    }

    public function testStubbornnessHasNoImmediateEffect(): void
    {
        $state = $this->boardState(hands: [1 => [102]]);
        $state->startTurn(1);

        $this->plays->playMood($state, 1, 102, new PlayerChoices([]));

        self::assertTrue($state->isInPlay(102));
        self::assertSame(0, $state->playsRemaining());
    }

    public function testGenerosityTagsAnOpponentForANextTurnGrant(): void
    {
        $state = $this->boardState(hands: [1 => [120]]);
        $state->startTurn(1);

        $this->plays->playMood($state, 1, 120, new PlayerChoices(['target_player_id' => 2]));

        self::assertSame(2, $state->effectState(120, 'banksExtraPlayForPlayerId'));
    }

    public function testGenerosityRejectsTargetingYourself(): void
    {
        $state = $this->boardState(hands: [1 => [120]]);
        $state->startTurn(1);

        $this->expectException(InvalidChoiceException::class);
        $this->plays->playMood($state, 1, 120, new PlayerChoices(['target_player_id' => 1]));
    }

    public function testJoyTagsItselfForANextTurnGrant(): void
    {
        $state = $this->boardState(hands: [1 => [125]]);
        $state->startTurn(1);

        $this->plays->playMood($state, 1, 125, new PlayerChoices([]));

        self::assertSame(1, $state->effectState(125, 'banksExtraPlayForPlayerId'));
    }

    public function testArrogancePausesForTheOpponentsOwnChoiceThenTagsItToReturn(): void
    {
        $state = $this->boardState(hands: [1 => [82], 2 => [8]]); // Arrogance; Dignity (white) for p2
        $state->moveHandToInPlay(2, 8);
        $state->startTurn(1);

        $choices = new PlayerChoices(['opponent_player_id' => 2]);
        $result = $this->plays->playMood($state, 1, 82, $choices);

        self::assertTrue($result->isPending);
        self::assertCount(1, $result->pendingDecisions);
        self::assertSame(2, $result->pendingDecisions[0]->targetPlayerId);
        self::assertSame(2, $state->ownerOf(8)); // not taken yet

        $this->plays->resolvePendingDecisions(
            $state, 82, 1, $choices, $choices, 0,
            ['chosen_mood_id' => new PlayerChoices(['chosen_mood_id' => 8])],
        );

        self::assertSame(1, $state->ownerOf(8));
        self::assertSame(
            ['sourceCardId' => 82, 'ownerId' => 2, 'heldByPlayerId' => 1],
            $state->effectState(8, 'returnsToOwnerIfCardLeavesPlay'),
        );
    }

    public function testArroganceDoesNothingWhenTheOpponentHasNoQualifyingMoods(): void
    {
        $state = $this->boardState(hands: [1 => [82], 2 => [55]]); // Apathy is black, doesn't qualify
        $state->moveHandToInPlay(2, 55);
        $state->startTurn(1);

        $this->plays->playMood($state, 1, 82, new PlayerChoices(['opponent_player_id' => 2]));

        self::assertSame(2, $state->ownerOf(55));
    }

    public function testArroganceRejectsTargetingYourself(): void
    {
        $state = $this->boardState(hands: [1 => [82]]);
        $state->startTurn(1);

        $this->expectException(InvalidChoiceException::class);
        $this->plays->playMood($state, 1, 82, new PlayerChoices(['opponent_player_id' => 1]));
    }

    public function testArroganceDoesNothingWhenDeclined(): void
    {
        $state = $this->boardState(hands: [1 => [82]]);
        $state->startTurn(1);

        $this->plays->playMood($state, 1, 82, new PlayerChoices([]));

        self::assertTrue($state->isInPlay(82));
    }

    public function testArroganceReturnsTheTakenMoodWhenItLeavesPlay(): void
    {
        $state = $this->boardState(hands: [1 => [82], 2 => [8]]);
        $state->moveHandToInPlay(2, 8);
        $state->startTurn(1);
        $choices = new PlayerChoices(['opponent_player_id' => 2]);
        $this->plays->playMood($state, 1, 82, $choices);
        $this->plays->resolvePendingDecisions(
            $state, 82, 1, $choices, $choices, 0,
            ['chosen_mood_id' => new PlayerChoices(['chosen_mood_id' => 8])],
        );
        self::assertSame(1, $state->ownerOf(8));

        $state->moveInPlayToDiscard(82);

        self::assertSame(2, $state->ownerOf(8));
        self::assertFalse($state->isInPlay(82));
        self::assertTrue($state->isInPlay(8));
    }

    public function testArroganceDoesNotReturnTheMoodIfNoLongerHeldWhenItLeavesPlay(): void
    {
        $state = $this->boardState(hands: [1 => [82], 2 => [8]]);
        $state->moveHandToInPlay(2, 8);
        $state->startTurn(1);
        $choices = new PlayerChoices(['opponent_player_id' => 2]);
        $this->plays->playMood($state, 1, 82, $choices);
        $this->plays->resolvePendingDecisions(
            $state, 82, 1, $choices, $choices, 0,
            ['chosen_mood_id' => new PlayerChoices(['chosen_mood_id' => 8])],
        );
        self::assertSame(1, $state->ownerOf(8));

        $state->giveInPlayToPlayer(8, 3); // player1 gives it away before Arrogance leaves play
        $state->moveInPlayToDiscard(82);

        self::assertSame(3, $state->ownerOf(8));
    }

    public function testFaithsSuppressionClearsAutomaticallyWhenItLeavesPlay(): void
    {
        $state = $this->boardState(hands: [1 => [12, 27, 9]]); // Faith, Ambivalence (blue, qualifies), Discipline to suppress
        $state->moveHandToInPlay(1, 9);
        $state->startTurn(1);
        $this->plays->playMood($state, 1, 12, new PlayerChoices(['discard_card_id' => 27, 'target_mood_id' => 9]));
        self::assertTrue($state->isSuppressed(9));

        $state->moveInPlayToHand(12); // Faith leaves play

        self::assertFalse($state->isSuppressed(9));
    }

    public function testScornSuppressesAnyMoodWhenPlayed(): void
    {
        $state = $this->boardState(hands: [1 => [24, 5]]); // Scorn, Complacency
        $state->moveHandToInPlay(1, 5);
        $state->startTurn(1);

        $this->plays->playMood($state, 1, 24, new PlayerChoices(['target_mood_id' => 5]));

        self::assertTrue($state->isSuppressed(5));
    }

    public function testScornReactsToASubsequentPlaySharingItsColor(): void
    {
        $state = $this->boardState(hands: [1 => [24, 5, 3, 7]]); // Scorn, Complacency, Charity (white), Courage (white)
        $state->moveHandToInPlay(1, 5);
        $state->startTurn(1);
        $state->grantExtraPlay(2); // enough plays for Scorn, Charity, and Courage

        $this->plays->playMood($state, 1, 24, new PlayerChoices(['target_mood_id' => 5])); // Scorn's mandatory suppression
        $this->plays->playMood($state, 1, 3, new PlayerChoices([])); // Charity -- grants an extra play
        $this->plays->playMood($state, 1, 7, new PlayerChoices(['scorn_suppress_target' => 3])); // Courage (white) triggers the reaction

        self::assertTrue($state->isSuppressed(3));
    }

    public function testScornRejectsAReactionTargetNotSharingColor(): void
    {
        $state = $this->boardState(hands: [1 => [24, 5, 55, 7]]); // Scorn, Complacency, Apathy (black), Courage (white)
        $state->moveHandToInPlay(1, 5);
        $state->moveHandToInPlay(1, 55);
        $state->startTurn(1);
        $state->grantExtraPlay(1);
        $this->plays->playMood($state, 1, 24, new PlayerChoices(['target_mood_id' => 5]));

        $this->expectException(InvalidChoiceException::class);
        $this->plays->playMood($state, 1, 7, new PlayerChoices(['scorn_suppress_target' => 55]));
    }

    public function testScornReactionDoesNothingWhenDeclined(): void
    {
        $state = $this->boardState(hands: [1 => [24, 5, 7]]);
        $state->moveHandToInPlay(1, 5);
        $state->startTurn(1);
        $state->grantExtraPlay(1);
        $this->plays->playMood($state, 1, 24, new PlayerChoices(['target_mood_id' => 5]));

        $this->plays->playMood($state, 1, 7, new PlayerChoices([]));

        self::assertTrue($state->isInPlay(7));
        self::assertFalse($state->isSuppressed(7));
    }

    public function testValidationGrantsAnExtraPlayWhenPlayed(): void
    {
        $state = $this->boardState(hands: [1 => [26, 5]]);
        $state->startTurn(1);

        $this->plays->playMood($state, 1, 26, new PlayerChoices([]));

        self::assertSame(1, $state->playsRemaining());
    }

    public function testValidationReactsToALowValuedSubsequentPlay(): void
    {
        $state = $this->boardState(hands: [1 => [26, 20, 5]]); // Validation, Pacifism (value 1), Complacency
        $state->startTurn(1);

        $this->plays->playMood($state, 1, 26, new PlayerChoices([])); // grants 1 extra play
        $this->plays->playMood($state, 1, 20, new PlayerChoices(['validation_extra_play' => true])); // Pacifism, value 1
        self::assertSame(1, $state->playsRemaining());

        $this->plays->playMood($state, 1, 5, new PlayerChoices([]));

        self::assertSame(0, $state->playsRemaining());
    }

    public function testValidationDoesNotReactToAHighValuedSubsequentPlay(): void
    {
        $state = $this->boardState(hands: [1 => [26, 5]]); // Validation, Complacency (value 4)
        $state->startTurn(1);

        $this->plays->playMood($state, 1, 26, new PlayerChoices([])); // grants 1 extra play
        $this->plays->playMood($state, 1, 5, new PlayerChoices(['validation_extra_play' => true]));

        self::assertSame(0, $state->playsRemaining());
    }

    public function testCompulsionPausesForTheTargetsOwnChoiceThenResolvesTheTransfer(): void
    {
        $state = $this->boardState(hands: [1 => [86], 2 => [3, 7]]);
        $state->startTurn(1);

        $choices = new PlayerChoices(['target_player_id' => 2]);
        $result = $this->plays->playMood($state, 1, 86, $choices);

        self::assertTrue($result->isPending);
        self::assertCount(1, $result->pendingDecisions);
        $decision = $result->pendingDecisions[0];
        self::assertSame('given_card_id', $decision->key);
        self::assertSame(2, $decision->targetPlayerId);
        self::assertSame('compulsion_give_card', $decision->decisionType);
        self::assertSame('hand_card', $decision->field['type']);
        // Compulsion itself is already in play (its own cost/grant already
        // resolved before the pause); nothing has been transferred yet --
        // still waiting on player 2's own answer.
        self::assertTrue($state->isInPlay(86));
        self::assertSame([], $state->hand(1));
        self::assertSame([3, 7], $state->hand(2));

        $finalResult = $this->plays->resolvePendingDecisions(
            $state,
            86,
            1,
            $choices,
            $choices,
            0,
            ['given_card_id' => new PlayerChoices(['given_card_id' => 3])],
        );

        self::assertFalse($finalResult->isPending);
        $p1Hand = $state->hand(1);
        $p2Hand = $state->hand(2);
        self::assertSame([3], $p1Hand);
        self::assertSame([7], $p2Hand);
    }

    public function testCompulsionDoesNothingWhenTargetHasNoCards(): void
    {
        $state = $this->boardState(hands: [1 => [86], 2 => []]);
        $state->startTurn(1);

        $this->plays->playMood($state, 1, 86, new PlayerChoices(['target_player_id' => 2]));

        self::assertSame([], $state->hand(1));
    }

    public function testCompulsionRejectsTargetingYourself(): void
    {
        $state = $this->boardState(hands: [1 => [86]]);
        $state->startTurn(1);

        $this->expectException(InvalidChoiceException::class);
        $this->plays->playMood($state, 1, 86, new PlayerChoices(['target_player_id' => 1]));
    }

    public function testIntimidationPausesForTheTargetsOwnChoiceThenGrantsItAsAnExtraPlay(): void
    {
        $state = $this->boardState(hands: [1 => [67], 2 => [3]]);
        $state->startTurn(1);

        $choices = new PlayerChoices(['target_player_id' => 2]);
        $result = $this->plays->playMood($state, 1, 67, $choices);

        self::assertTrue($result->isPending);
        self::assertCount(1, $result->pendingDecisions);
        $decision = $result->pendingDecisions[0];
        self::assertSame('revealed_card_id', $decision->key);
        self::assertSame(2, $decision->targetPlayerId);
        self::assertSame('intimidation_reveal_card', $decision->decisionType);
        self::assertSame([3], $state->hand(2)); // not revealed yet

        $this->plays->resolvePendingDecisions(
            $state, 67, 1, $choices, $choices, 0,
            ['revealed_card_id' => new PlayerChoices(['revealed_card_id' => 3])],
        );

        self::assertContains(3, $state->hand(1));
        self::assertSame(1, $state->playsRemaining());

        $this->plays->playMood($state, 1, 3, new PlayerChoices([]));

        self::assertTrue($state->isInPlay(3));
    }

    public function testIntimidationsGrantOnlyAllowsTheRevealedCard(): void
    {
        $state = $this->boardState(hands: [1 => [67, 5], 2 => [3]]);
        $state->startTurn(1);
        $choices = new PlayerChoices(['target_player_id' => 2]);
        $this->plays->playMood($state, 1, 67, $choices);
        $this->plays->resolvePendingDecisions(
            $state, 67, 1, $choices, $choices, 0,
            ['revealed_card_id' => new PlayerChoices(['revealed_card_id' => 3])],
        );

        $this->expectException(IllegalPlayException::class);
        $this->plays->playMood($state, 1, 5, new PlayerChoices([]));
    }

    public function testIntimidationDoesNothingWhenDeclined(): void
    {
        $state = $this->boardState(hands: [1 => [67]]);
        $state->startTurn(1);

        $result = $this->plays->playMood($state, 1, 67, new PlayerChoices([]));

        self::assertFalse($result->isPending);
        self::assertTrue($state->isInPlay(67));
        self::assertSame(0, $state->playsRemaining());
    }

    public function testIntimidationDoesNothingWhenTargetHasNoCards(): void
    {
        $state = $this->boardState(hands: [1 => [67], 2 => []]);
        $state->startTurn(1);

        $result = $this->plays->playMood($state, 1, 67, new PlayerChoices(['target_player_id' => 2]));

        self::assertFalse($result->isPending);
        self::assertSame([], $state->hand(1));
        self::assertSame(0, $state->playsRemaining());
    }

    public function testDisillusionmentNeverDiscardsItself(): void
    {
        $state = $this->boardState(hands: [1 => [10]]);
        $state->startTurn(1);

        $choices = new PlayerChoices([]);
        $result = $this->plays->playMood($state, 1, 10, $choices);
        self::assertTrue($result->isPending);

        $this->plays->resolvePendingDecisions(
            $state, 10, 1, $choices, $choices, 0,
            [
                'chosen_color_2' => new PlayerChoices(['chosen_color_2' => 'white']),
                'chosen_color_3' => new PlayerChoices(['chosen_color_3' => 'white']),
                'chosen_color_1' => new PlayerChoices(['chosen_color_1' => 'white']),
            ],
        );

        self::assertTrue($state->isInPlay(10));
    }

    public function testDisillusionmentPausesForEveryPlayersOwnColorChoiceThenDiscardsMatches(): void
    {
        $state = $this->boardState(hands: [1 => [10], 2 => [9, 28, 53]]);
        $state->moveHandToInPlay(2, 9); // Discipline, white
        $state->moveHandToInPlay(2, 28); // Anxiety, blue
        $state->moveHandToInPlay(2, 53); // Ambition, black
        $state->startTurn(1);

        $choices = new PlayerChoices([]);
        $result = $this->plays->playMood($state, 1, 10, $choices);

        self::assertTrue($result->isPending);
        self::assertCount(3, $result->pendingDecisions);
        // Starts with the next player in turn order (2, 3), wraps around to
        // end with the acting player themselves (1) -- every player at the
        // table is asked, matching the original random implementation's
        // unconditional per-player loop.
        self::assertSame('chosen_color_2', $result->pendingDecisions[0]->key);
        self::assertSame(2, $result->pendingDecisions[0]->targetPlayerId);
        self::assertSame('chosen_color_3', $result->pendingDecisions[1]->key);
        self::assertSame(3, $result->pendingDecisions[1]->targetPlayerId);
        self::assertSame('chosen_color_1', $result->pendingDecisions[2]->key);
        self::assertSame(1, $result->pendingDecisions[2]->targetPlayerId);

        $this->plays->resolvePendingDecisions(
            $state, 10, 1, $choices, $choices, 0,
            [
                'chosen_color_2' => new PlayerChoices(['chosen_color_2' => 'black']),
                'chosen_color_3' => new PlayerChoices(['chosen_color_3' => 'blue']),
                'chosen_color_1' => new PlayerChoices(['chosen_color_1' => 'black']),
            ],
        );

        self::assertTrue($state->isInPlay(10));
        self::assertTrue($state->isInPlay(9)); // white, not chosen by anyone -- survives
        self::assertFalse($state->isInPlay(28)); // blue
        self::assertFalse($state->isInPlay(53)); // black
    }

    public function testDuplicityGrantsAnExtraPlayWhenPlayed(): void
    {
        $state = $this->boardState(hands: [1 => [37, 5]]);
        $state->startTurn(1);

        $this->plays->playMood($state, 1, 37, new PlayerChoices([]));

        self::assertSame(1, $state->playsRemaining());
    }

    public function testDuplicityPausesForTheActingPlayersOwnRepeatOfferThenRepeatsWithFreshChoices(): void
    {
        $state = $this->boardState(hands: [1 => [37, 8, 3, 7]]); // Duplicity, Dignity, Charity (value 1), Courage (value 1)
        $state->startTurn(1);
        $state->grantExtraPlay(1);
        $this->plays->playMood($state, 1, 37, new PlayerChoices([]));

        $choices = new PlayerChoices(['discard_card_id' => 3]);
        $result = $this->plays->playMood($state, 1, 8, $choices);

        self::assertTrue($result->isPending);
        self::assertCount(1, $result->pendingDecisions);
        $decision = $result->pendingDecisions[0];
        self::assertSame('duplicity_repeat', $decision->key);
        self::assertSame(1, $decision->targetPlayerId); // the acting player themselves, not an opponent
        self::assertSame('duplicity_repeat_offer', $decision->decisionType);
        self::assertSame('nested', $decision->field['type']);
        // Invocation 0's own discard already happened -- only the optional
        // repeat is on hold.
        self::assertContains(3, $state->discardPile());
        self::assertSame(5, $state->valueOf(8));

        $finalResult = $this->plays->resolvePendingDecisions(
            $state, 8, 1, $choices, $choices, 0,
            ['duplicity_repeat' => new PlayerChoices([
                'duplicity_repeat' => ['repeat' => true, 'choices' => ['discard_card_id' => 7]],
            ])],
        );

        self::assertFalse($finalResult->isPending);
        self::assertSame(5, $state->valueOf(8));
        self::assertContains(3, $state->discardPile());
        self::assertContains(7, $state->discardPile());
    }

    public function testDuplicityDoesNotRepeatWhenDeclined(): void
    {
        $state = $this->boardState(hands: [1 => [37, 8, 3]]);
        $state->startTurn(1);
        $state->grantExtraPlay(1);
        $this->plays->playMood($state, 1, 37, new PlayerChoices([]));

        $choices = new PlayerChoices(['discard_card_id' => 3]);
        $result = $this->plays->playMood($state, 1, 8, $choices);
        self::assertTrue($result->isPending);

        $finalResult = $this->plays->resolvePendingDecisions(
            $state, 8, 1, $choices, $choices, 0,
            ['duplicity_repeat' => new PlayerChoices(['duplicity_repeat' => ['repeat' => false]])],
        );

        self::assertFalse($finalResult->isPending);
        self::assertSame([3], $state->discardPile());
    }

    public function testDuplicityDoesNotOfferToRepeatItsOwnJustPlayedInstance(): void
    {
        $state = $this->boardState(hands: [1 => [37]]);
        $state->startTurn(1);

        $result = $this->plays->playMood($state, 1, 37, new PlayerChoices([]));

        self::assertFalse($result->isPending);
        self::assertSame(1, $state->playsRemaining()); // only Duplicity's own single grant
    }

    /**
     * The actual "repeat of a repeat" gap this closes: every Duplicity-
     * effective mood the player owns grants its own independent repeat --
     * a real Duplicity plus a Creativity currently copying one (the only
     * way to ever have two, since every card including Duplicity itself
     * is single-copy) means the played card's effect can happen three
     * times total (original + 2 repeats), each pause offered and answered
     * one at a time rather than needing to be pre-declared as a deeply
     * nested tree of choices up front.
     */
    public function testDuplicityGrantsOneIndependentRepeatPerDuplicityEffectiveSourceInPlay(): void
    {
        $state = $this->boardState(hands: [1 => [37, 32, 8, 3, 4, 6]]); // Duplicity, Creativity, Dignity, 3 discard fodder
        $state->startTurn(1);
        $state->moveHandToInPlay(1, 37); // real Duplicity
        $state->moveHandToInPlay(1, 32, 37); // Creativity, copying Duplicity -- a second, independent source

        $choices = new PlayerChoices(['discard_card_id' => 3]);
        $result = $this->plays->playMood($state, 1, 8, $choices);

        self::assertTrue($result->isPending);
        self::assertContains(3, $state->discardPile());

        $result2 = $this->plays->resolvePendingDecisions(
            $state, 8, 1, $choices, $choices, $result->invocationSeq,
            ['duplicity_repeat' => new PlayerChoices(['duplicity_repeat' => ['repeat' => true, 'choices' => ['discard_card_id' => 4]]])],
        );
        self::assertTrue($result2->isPending, 'a second independent Duplicity source should still be available');
        self::assertContains(4, $state->discardPile());

        $result3 = $this->plays->resolvePendingDecisions(
            $state, 8, 1, $choices, $choices, $result2->invocationSeq,
            ['duplicity_repeat' => new PlayerChoices(['duplicity_repeat' => ['repeat' => true, 'choices' => ['discard_card_id' => 6]]])],
        );
        self::assertFalse($result3->isPending, 'no Duplicity sources left -- the chain has to stop here');
        self::assertContains(6, $state->discardPile());
        self::assertSame(5, $state->valueOf(8));
    }

    /**
     * "Each time you play ANOTHER mood" -- a Duplicity-effective source
     * never offers to repeat its own just-played instance via itself, but
     * a *different*, already-in-play Duplicity-effective source still can.
     * Playing the real Duplicity while a Creativity is already copying one
     * lets that Creativity offer exactly one repeat of the just-played
     * Duplicity's own "grant an extra play" effect -- two grants total if
     * accepted, one from the original play and one from the repeat.
     */
    public function testDuplicitysOwnPlayCanStillBeRepeatedByAnAlreadyInPlaySeparateSource(): void
    {
        $state = $this->boardState(hands: [1 => [37, 32]]);
        $state->startTurn(1);
        $state->moveHandToInPlay(1, 32, 37); // Creativity, already in play, copying Duplicity

        $result = $this->plays->playMood($state, 1, 37, new PlayerChoices([])); // playing the *real* Duplicity

        self::assertTrue($result->isPending, 'the already-in-play Creativity-copy-of-Duplicity should offer one repeat');
        self::assertSame(1, $state->playsRemaining()); // only the original play's own grant so far

        $result2 = $this->plays->resolvePendingDecisions(
            $state, 37, 1, new PlayerChoices([]), new PlayerChoices([]), $result->invocationSeq,
            ['duplicity_repeat' => new PlayerChoices(['duplicity_repeat' => ['repeat' => true, 'choices' => []]])],
        );
        self::assertFalse($result2->isPending, 'the just-played Duplicity itself never offers to repeat its own instance');
        self::assertSame(2, $state->playsRemaining()); // original grant + the repeat's own grant
    }

    public function testChaosReassignsOwnershipOfEveryMoodInPlay(): void
    {
        $state = $this->boardState(hands: [1 => [85], 2 => [3], 3 => [7]]);
        $state->moveHandToInPlay(2, 3);
        $state->moveHandToInPlay(3, 7);
        $state->startTurn(1);

        $this->plays->playMood($state, 1, 85, new PlayerChoices([]));

        self::assertTrue($state->isInPlay(85));
        self::assertTrue($state->isInPlay(3));
        self::assertTrue($state->isInPlay(7));
        foreach ([$state->ownerOf(85), $state->ownerOf(3), $state->ownerOf(7)] as $owner) {
            self::assertContains($owner, [1, 2, 3]);
        }
    }

    public function testChaosDealsDeterministicallyStartingWithTheActingPlayer(): void
    {
        $state = $this->boardState(hands: [1 => [85], 2 => [3], 3 => [7]]);
        $state->moveHandToInPlay(2, 3);
        $state->moveHandToInPlay(3, 7);
        $state->startTurn(1);

        // Deterministic with this seed: shuffle([3, 7, 85]) -> [7, 85, 3],
        // dealt starting with player 1 (turn order [1, 2, 3] unrotated).
        mt_srand(7);
        $this->plays->playMood($state, 1, 85, new PlayerChoices([]));

        self::assertSame(1, $state->ownerOf(7));
        self::assertSame(2, $state->ownerOf(85));
        self::assertSame(3, $state->ownerOf(3));
    }

    public function testExhilarationRequiresDiscardingOneOfYourOwnMoods(): void
    {
        $state = $this->boardState(hands: [1 => [89]]);
        $state->startTurn(1);

        $this->expectException(IllegalPlayException::class);
        $this->plays->playMood($state, 1, 89, new PlayerChoices([]));
    }

    public function testExhilarationDiscardsTheChosenMoodAsItsCost(): void
    {
        $state = $this->boardState(hands: [1 => [89, 3]]); // Exhilaration, Charity (already in play as its own cost target)
        $state->moveHandToInPlay(1, 3);
        $state->startTurn(1);

        $this->plays->playMood($state, 1, 89, new PlayerChoices(['discard_mood_id' => 3]));

        self::assertTrue($state->isInPlay(89));
        self::assertContains(3, $state->discardPile());
    }

    public function testBlissRecordsTheDiscardedCardsColorAndDiscardsIt(): void
    {
        $state = $this->boardState(hands: [1 => [108, 3]]); // Bliss, Charity (white)
        $state->startTurn(1);

        $this->plays->playMood($state, 1, 108, new PlayerChoices(['discard_card_id' => 3]));

        self::assertTrue($state->isInPlay(108));
        self::assertContains(3, $state->discardPile());
        self::assertSame('white', $state->effectState(108, 'blissColor'));
    }

    public function testBlissCannotBePlayedWithNoOtherCardInHand(): void
    {
        $state = $this->boardState(hands: [1 => [108]]); // Bliss is the only card in hand
        $state->startTurn(1);

        $this->expectException(IllegalPlayException::class);
        $this->plays->playMood($state, 1, 108, new PlayerChoices(['discard_card_id' => 108]));
    }

    public function testBlissRejectsDiscardingItselfToPayItsOwnCost(): void
    {
        $state = $this->boardState(hands: [1 => [108, 3]]); // Bliss, Charity -- another card is available
        $state->startTurn(1);

        $this->expectException(InvalidChoiceException::class);
        $this->plays->playMood($state, 1, 108, new PlayerChoices(['discard_card_id' => 108]));
    }

    public function testEncouragementUsesTheHigherOfBaseAndDiceValueForTheChosenMood(): void
    {
        $state = $this->boardState(hands: [1 => [11, 9]]); // Encouragement, Discipline (base 6, dice 3 -- base is higher)
        $state->moveHandToInPlay(1, 9);
        $state->startTurn(1);

        $this->plays->playMood($state, 1, 11, new PlayerChoices(['target_mood_id' => 9]));

        self::assertSame(6, $state->valueOf(9));
    }

    public function testEncouragementCanTargetAnOpponentsMood(): void
    {
        $state = $this->boardState(hands: [1 => [11], 2 => [8]]); // Encouragement; Dignity (base 3, dice 5) for p2
        $state->moveHandToInPlay(2, 8);
        $state->startTurn(1);

        $this->plays->playMood($state, 1, 11, new PlayerChoices(['target_mood_id' => 8]));

        self::assertSame(5, $state->valueOf(8));
    }

    public function testEncouragementRejectsAMoodWithoutDice(): void
    {
        $state = $this->boardState(hands: [1 => [11, 3]]); // Encouragement, Charity (no dice value)
        $state->moveHandToInPlay(1, 3);
        $state->startTurn(1);

        $this->expectException(InvalidChoiceException::class);
        $this->plays->playMood($state, 1, 11, new PlayerChoices(['target_mood_id' => 3]));
    }

    public function testEncouragementDoesNothingWhenDeclined(): void
    {
        $state = $this->boardState(hands: [1 => [11, 9]]);
        $state->moveHandToInPlay(1, 9);
        $state->startTurn(1);

        $this->plays->playMood($state, 1, 11, new PlayerChoices([]));

        self::assertSame(6, $state->valueOf(9)); // Discipline's own base value, unaffected
    }

    public function testIdealismGrantsAnExtraPlayWhenPlayed(): void
    {
        $state = $this->boardState(hands: [1 => [16, 5]]);
        $state->startTurn(1);

        $this->plays->playMood($state, 1, 16, new PlayerChoices([]));

        self::assertSame(1, $state->playsRemaining());
    }

    public function testIdealismBoostsEveryOwnedMoodWithDiceButNotOpponents(): void
    {
        $state = $this->boardState(hands: [1 => [16, 9], 2 => [8]]); // Idealism; Discipline (base 6, dice 3) for p1; Dignity (base 3, dice 5) for p2
        $state->moveHandToInPlay(1, 9);
        $state->moveHandToInPlay(2, 8);
        $state->startTurn(1);

        $this->plays->playMood($state, 1, 16, new PlayerChoices([]));

        self::assertSame(6, $state->valueOf(9)); // higher of base(6)/dice(3) -- p1's own mood
        self::assertSame(3, $state->valueOf(8)); // unaffected -- belongs to p2
    }

    public function testIdealismDoesNotAffectItsOwnValue(): void
    {
        $state = $this->boardState(hands: [1 => [16]]);
        $state->startTurn(1);

        $this->plays->playMood($state, 1, 16, new PlayerChoices([]));

        self::assertSame(0, $state->valueOf(16)); // Idealism itself has no dice value
    }

    public function testVulnerabilityValueIsBaseWithNoDiscardThisRound(): void
    {
        $state = $this->boardState(hands: [1 => [132]]);
        $state->startTurn(1);

        $this->plays->playMood($state, 1, 132, new PlayerChoices([]));

        self::assertSame(1, $state->valueOf(132));
    }

    public function testVulnerabilityValueBecomesDiceAfterAnyCardIsDiscardedThisRound(): void
    {
        $state = $this->boardState(hands: [1 => [132], 2 => [3]]);
        $state->startTurn(1);
        $this->plays->playMood($state, 1, 132, new PlayerChoices([]));
        self::assertSame(1, $state->valueOf(132));

        $state->moveHandToDiscard(2, 3); // a discard by any player counts, not just Vulnerability's owner

        self::assertSame(7, $state->valueOf(132));
    }

    public function testIsPlayableIsFalseWhenItIsNotThePlayersTurn(): void
    {
        $state = $this->boardState(hands: [1 => [3]]);
        $state->startTurn(2);

        self::assertFalse($this->plays->isPlayable($state, 1, 3));
    }

    public function testIsPlayableIsFalseForABannedColor(): void
    {
        // Reuses Doubt's own mechanics (see testDoubtBlocksPlayingABannedColorDuringTheFollowingRound)
        // rather than reaching into effectState directly, so this stays
        // accurate if that mechanism ever changes.
        $state = $this->boardState(hands: [1 => [36, 8], 2 => [7]]); // Doubt; Dignity (white); Courage (white)
        $state->startRound(1, 3);
        $state->startTurn(1);
        $this->plays->playMood($state, 1, 36, new PlayerChoices(['reveal_card_ids' => [8]]));

        $state->startRound(2, 4);
        $state->startTurn(2);

        self::assertFalse($this->plays->isPlayable($state, 2, 7));
    }

    public function testIsPlayableIsTrueForAnUnconditionalCardWithAnUnrestrictedGrant(): void
    {
        $state = $this->boardState(hands: [1 => [3]]);
        $state->startTurn(1);

        self::assertTrue($this->plays->isPlayable($state, 1, 3));
    }

    public function testIsPlayableRespectsARestrictedGrantLikeIntimidations(): void
    {
        // Mirrors IntimidationEffect's 'specific_card_ids' grant directly
        // rather than playing Intimidation itself, since only the
        // restriction's effect on isPlayable() is under test here.
        $state = $this->boardState(hands: [1 => [3, 7]]); // Charity, Courage
        $state->startTurn(1);
        $state->consumePlay(); // use up the unconditional grant from startTurn()
        $state->grantExtraPlay(1, ['type' => 'specific_card_ids', 'values' => [3]]);

        self::assertTrue($this->plays->isPlayable($state, 1, 3));
        self::assertFalse($this->plays->isPlayable($state, 1, 7));
    }

    public function testIsPlayableIsFalseWhenAToPlayCostCannotBePaid(): void
    {
        // Guile needs two *other* hand cards to discard -- with none, it
        // can't be played at all, matching GuileEffect::canPayToPlayCost().
        $state = $this->boardState(hands: [1 => [40]]);
        $state->startTurn(1);

        self::assertFalse($this->plays->isPlayable($state, 1, 40));
    }

    public function testIsPlayableIsTrueWhenAToPlayCostCanBePaid(): void
    {
        $state = $this->boardState(hands: [1 => [40, 3, 7]]); // Guile, Charity, Courage
        $state->startTurn(1);

        self::assertTrue($this->plays->isPlayable($state, 1, 40));
    }
}
