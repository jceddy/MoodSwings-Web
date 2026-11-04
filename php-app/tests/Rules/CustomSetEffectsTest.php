<?php

declare(strict_types=1);

namespace MoodSwings\Tests\Rules;

use MoodSwings\Rules\BoardState;
use MoodSwings\Rules\CardChoiceSchema;
use MoodSwings\Rules\DefaultEffectRegistry;
use MoodSwings\Rules\Exceptions\IllegalPlayException;
use MoodSwings\Rules\Exceptions\InvalidChoiceException;
use MoodSwings\Rules\MoodPlayService;
use MoodSwings\Rules\PlayerChoices;
use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;

/**
 * Effects of the community set "Emotional Baggage" (XEMB) cards whose effects need no special engine
 * hooks (migration 0497), run against the real catalog rows so the printed values, colors and
 * effect keys are checked together with the effect classes in Rules/Effects/Custom.
 */
final class CustomSetEffectsTest extends TestCase
{
    /** @var array<int, array<string, mixed>> */
    private static array $catalog = [];
    /** @var array<string, int> */
    private static array $idByName = [];

    private MoodPlayService $plays;

    public static function setUpBeforeClass(): void
    {
        $host = getenv('TEST_DB_HOST') ?: '127.0.0.1';
        $port = getenv('TEST_DB_PORT') ?: '3306';
        $name = getenv('TEST_DB_NAME') ?: 'moodswings_test';
        try {
            $pdo = new PDO(
                "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4",
                getenv('TEST_DB_USER') ?: 'root',
                getenv('TEST_DB_PASSWORD') ?: '',
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
            );
        } catch (PDOException $e) {
            self::markTestSkipped('Test database unavailable: ' . $e->getMessage());
        }

        foreach ($pdo->query('SELECT * FROM cards WHERE is_token = 0')->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $id = (int) $row['id'];
            self::$idByName[$row['name']] = $id;
            self::$catalog[$id] = [
                'color' => $row['color'],
                'rarity' => $row['rarity'],
                'baseValue' => (int) $row['base_value'],
                'altValue' => $row['alt_value'] !== null ? (int) $row['alt_value'] : null,
                'effectKey' => $row['effect_key'],
                'hasToPlay' => (bool) $row['has_to_play_ability'],
                'hasWhileInPlay' => (bool) $row['has_while_in_play_ability'],
                'hasAfterPlaying' => (bool) $row['has_after_playing_ability'],
                'rulesText' => $row['rules_text'],
                'isToken' => false,
                'artSetCode' => $row['art_set_code'],
                'draftPriorityScore' => (int) $row['draft_priority_score'],
            ];
        }
        if (!isset(self::$idByName['Anticipation'])) {
            self::markTestSkipped('Migration 0497 (custom set batch 1) is not applied to the test database');
        }
    }

    protected function setUp(): void
    {
        $this->plays = new MoodPlayService(DefaultEffectRegistry::build());
    }

    private function id(string $name): int
    {
        return self::$idByName[$name] ?? self::fail("No card named {$name}");
    }

    /** @return int[] */
    private function ids(string ...$names): array
    {
        return array_map(fn (string $n) => $this->id($n), $names);
    }

    /**
     * @param array<int, string[]> $handNames playerId => card names
     * @param string[] $deckNames
     * @param string[] $discardNames
     */
    private function state(array $handNames, array $deckNames = [], array $discardNames = []): BoardState
    {
        $hands = [];
        foreach ([1, 2, 3] as $p) {
            $hands[$p] = $this->ids(...($handNames[$p] ?? []));
        }

        return new BoardState(
            self::$catalog,
            DefaultEffectRegistry::build(),
            [1, 2, 3],
            $hands,
            $this->ids(...$deckNames),
            $this->ids(...$discardNames),
        );
    }

    /** Puts cards straight into play (no after-playing effect), as if played on an earlier turn. */
    private function seat(BoardState $state, int $playerId, string ...$names): void
    {
        foreach ($names as $name) {
            $state->moveHandToInPlay($playerId, $this->id($name));
        }
    }

    /** @param array<string, mixed> $choices */
    private function play(BoardState $state, int $playerId, string $name, array $choices = []): void
    {
        $this->plays->playMood($state, $playerId, $this->id($name), new PlayerChoices($choices));
    }

    // ---- conditional values ----

    public function testAnticipationIsSixWithFourCardsInHand(): void
    {
        $state = $this->state([1 => ['Anticipation', 'Apathy', 'Complacency', 'Creativity']]);
        $state->startTurn(1);
        $this->seat($state, 1, 'Anticipation');
        self::assertSame(3, $state->valueOf($this->id('Anticipation')));

        $state = $this->state([1 => ['Anticipation', 'Apathy', 'Complacency', 'Creativity', 'Courage']]);
        $state->startTurn(1);
        $this->seat($state, 1, 'Anticipation');
        self::assertSame(6, $state->valueOf($this->id('Anticipation')));
    }

    public function testComposureNeedsMoreCardsThanEveryOtherPlayer(): void
    {
        $state = $this->state([1 => ['Composure', 'Apathy', 'Complacency'], 2 => ['Creativity'], 3 => ['Courage']]);
        $state->startTurn(1);
        $this->seat($state, 1, 'Composure');
        self::assertSame(6, $state->valueOf($this->id('Composure')));

        $state = $this->state([1 => ['Composure', 'Apathy'], 2 => ['Creativity'], 3 => ['Courage']]);
        $state->startTurn(1);
        $this->seat($state, 1, 'Composure');
        self::assertSame(2, $state->valueOf($this->id('Composure')), 'tied with another player is not "more than each other player"');
    }

    public function testTemperanceNeedsFewerCardsThanEveryOtherPlayer(): void
    {
        $state = $this->state([1 => ['Temperance'], 2 => ['Creativity', 'Apathy'], 3 => ['Courage']]);
        $state->startTurn(1);
        $this->seat($state, 1, 'Temperance');
        self::assertSame(5, $state->valueOf($this->id('Temperance')));

        $state = $this->state([1 => ['Temperance', 'Apathy'], 2 => ['Creativity'], 3 => ['Courage']]);
        $state->startTurn(1);
        $this->seat($state, 1, 'Temperance');
        self::assertSame(3, $state->valueOf($this->id('Temperance')));
    }

    public function testHellbentIsSevenWithAnEmptyHand(): void
    {
        $state = $this->state([1 => ['Hellbent']]);
        $state->startTurn(1);
        $this->seat($state, 1, 'Hellbent');
        self::assertSame(7, $state->valueOf($this->id('Hellbent')));

        $state = $this->state([1 => ['Hellbent', 'Apathy']]);
        $state->startTurn(1);
        $this->seat($state, 1, 'Hellbent');
        self::assertSame(2, $state->valueOf($this->id('Hellbent')));
    }

    public function testRuthlessnessChecksOpponentsHands(): void
    {
        $state = $this->state([1 => ['Ruthlessness'], 2 => ['Apathy']]);
        $state->startTurn(1);
        $this->seat($state, 1, 'Ruthlessness');
        self::assertSame(5, $state->valueOf($this->id('Ruthlessness')), 'player 3 has no cards in hand');

        $state = $this->state([1 => ['Ruthlessness'], 2 => ['Apathy'], 3 => ['Creativity']]);
        $state->startTurn(1);
        $this->seat($state, 1, 'Ruthlessness');
        self::assertSame(3, $state->valueOf($this->id('Ruthlessness')));
    }

    public function testPossessivenessCountsTheDiscardPile(): void
    {
        $state = $this->state([1 => ['Possessiveness']], discardNames: ['Apathy', 'Complacency', 'Creativity', 'Courage']);
        $state->startTurn(1);
        $this->seat($state, 1, 'Possessiveness');
        self::assertSame(0, $state->valueOf($this->id('Possessiveness')));

        $state = $this->state([1 => ['Possessiveness']], discardNames: ['Apathy', 'Complacency', 'Creativity', 'Courage', 'Charity']);
        $state->startTurn(1);
        $this->seat($state, 1, 'Possessiveness');
        self::assertSame(6, $state->valueOf($this->id('Possessiveness')));
    }

    public function testEquanimityCountsEvenNumberedMoodsIncludingZero(): void
    {
        // Equanimity itself prints 3 (odd). Apathy 4, Complacency 4, Creativity 0, Benevolence 2.
        $state = $this->state([1 => ['Equanimity', 'Apathy', 'Complacency', 'Creativity'], 2 => ['Benevolence']]);
        $state->startTurn(1);
        $this->seat($state, 1, 'Equanimity', 'Apathy', 'Complacency', 'Creativity');
        self::assertSame(3, $state->valueOf($this->id('Equanimity')), 'only three even-numbered moods');

        $this->seat($state, 2, 'Benevolence');
        self::assertSame(6, $state->valueOf($this->id('Equanimity')), 'a fourth even-numbered mood, owned by someone else, counts');
    }

    public function testExuberanceCountsOtherOwnRedMoods(): void
    {
        $state = $this->state([1 => ['Exuberance', 'Anger', 'Boredom'], 2 => []]);
        $state->startTurn(1);
        $this->seat($state, 1, 'Exuberance', 'Anger');
        self::assertSame(3, $state->valueOf($this->id('Exuberance')));
        $this->seat($state, 1, 'Boredom');
        self::assertSame(6, $state->valueOf($this->id('Exuberance')));
    }

    public function testSolidarityGrowsWithOtherTwos(): void
    {
        $state = $this->state([1 => ['Solidarity', 'Benevolence'], 2 => ['Conviction', 'Apathy']]);
        $state->startTurn(1);
        $this->seat($state, 1, 'Solidarity');
        self::assertSame(2, $state->valueOf($this->id('Solidarity')));
        $this->seat($state, 1, 'Benevolence');
        $this->seat($state, 2, 'Conviction', 'Apathy');
        self::assertSame(4, $state->valueOf($this->id('Solidarity')), 'Benevolence and Conviction print 2; Apathy does not');
    }

    // ---- extra-play grants ----

    public function testConfidenceOnlyAllowsAHighNumberedExtraPlay(): void
    {
        $state = $this->state([1 => ['Confidence', 'Creativity', 'Apathy']]);
        $state->startTurn(1);
        $this->play($state, 1, 'Confidence');
        self::assertSame(1, $state->playsRemaining());

        $this->expectException(IllegalPlayException::class);
        $this->play($state, 1, 'Creativity'); // prints 0
    }

    public function testConfidenceAllowsAFourFiveOrSix(): void
    {
        $state = $this->state([1 => ['Confidence', 'Creativity', 'Apathy']]);
        $state->startTurn(1);
        $this->play($state, 1, 'Confidence');
        $this->play($state, 1, 'Apathy'); // prints 4
        self::assertTrue($state->isInPlay($this->id('Apathy')));
    }

    public function testModestyRejectsAHighNumberedExtraPlay(): void
    {
        $state = $this->state([1 => ['Modesty', 'Creativity', 'Apathy']]);
        $state->startTurn(1);
        $this->play($state, 1, 'Modesty');

        $this->expectException(IllegalPlayException::class);
        $this->play($state, 1, 'Apathy');
    }

    public function testThriftLetsYouPlayALowMoodFromTheDiscardPile(): void
    {
        $state = $this->state([1 => ['Thrift']], discardNames: ['Creativity', 'Apathy']);
        $state->startTurn(1);
        $this->play($state, 1, 'Thrift');

        $this->play($state, 1, 'Creativity');
        self::assertTrue($state->isInPlay($this->id('Creativity')));
        self::assertSame([$this->id('Apathy')], $state->discardPile());
    }

    public function testDesperationNeedsFewerCardsThanEveryOtherPlayer(): void
    {
        $state = $this->state([1 => ['Desperation'], 2 => ['Apathy'], 3 => ['Creativity']]);
        $state->startTurn(1);
        $this->play($state, 1, 'Desperation');
        self::assertSame(1, $state->playsRemaining());

        $state = $this->state([1 => ['Desperation', 'Apathy'], 2 => ['Creativity'], 3 => ['Courage']]);
        $state->startTurn(1);
        $this->play($state, 1, 'Desperation');
        self::assertSame(0, $state->playsRemaining());
    }

    public function testCamaraderieNeedsTwoGreenAndOrBlueMoods(): void
    {
        $state = $this->state([1 => ['Camaraderie'], 2 => ['Creativity', 'Fear']]);
        $this->seat($state, 2, 'Creativity'); // blue
        $state->startTurn(1);
        $this->play($state, 1, 'Camaraderie');
        self::assertSame(0, $state->playsRemaining(), 'only one blue mood is in play');

        $state = $this->state([1 => ['Camaraderie'], 2 => ['Creativity', 'Fear']]);
        $this->seat($state, 2, 'Creativity', 'Fear'); // both blue
        $state->startTurn(1);
        $this->play($state, 1, 'Camaraderie');
        self::assertSame(1, $state->playsRemaining());
    }

    public function testFervorTagsTheExtraMoodToLeaveAfterScoring(): void
    {
        $state = $this->state([1 => ['Fervor', 'Apathy', 'Laziness'], 2 => ['Hate']]);
        $state->startTurn(1);
        $this->seat($state, 1, 'Apathy'); // black
        $this->seat($state, 2, 'Hate');   // black
        $this->play($state, 1, 'Fervor');
        self::assertSame(1, $state->playsRemaining());

        $this->play($state, 1, 'Laziness');
        self::assertNotNull($state->effectState($this->id('Laziness'), 'afterScoring'));
        self::assertNull($state->effectState($this->id('Fervor'), 'afterScoring'));
    }

    public function testFervorGrantsNothingWithoutTwoBlackOrGreenMoods(): void
    {
        $state = $this->state([1 => ['Fervor', 'Apathy']]);
        $state->startTurn(1);
        $this->seat($state, 1, 'Apathy');
        $this->play($state, 1, 'Fervor');
        self::assertSame(0, $state->playsRemaining());
    }

    public function testRashnessDiscardsALowOpposingMoodAndGrantsAPlay(): void
    {
        $state = $this->state([1 => ['Rashness', 'Apathy'], 2 => ['Creativity']]);
        $state->startTurn(1);
        $this->seat($state, 2, 'Creativity');
        $this->play($state, 1, 'Rashness', ['target_mood_id' => $this->id('Creativity')]);

        self::assertFalse($state->isInPlay($this->id('Creativity')));
        self::assertContains($this->id('Creativity'), $state->discardPile());
        self::assertSame(1, $state->playsRemaining());
    }

    public function testRashnessRejectsAMoodWorthMoreThanOne(): void
    {
        $state = $this->state([1 => ['Rashness'], 2 => ['Apathy']]);
        $state->startTurn(1);
        $this->seat($state, 2, 'Apathy');

        $this->expectException(InvalidChoiceException::class);
        $this->play($state, 1, 'Rashness', ['target_mood_id' => $this->id('Apathy')]);
    }

    // ---- other after-playing effects ----

    public function testFrenzyLeavesPlayAfterScoring(): void
    {
        $state = $this->state([1 => ['Frenzy']]);
        $state->startTurn(1);
        $this->play($state, 1, 'Frenzy');
        self::assertSame(6, $state->valueOf($this->id('Frenzy')));
        self::assertNotNull($state->effectState($this->id('Frenzy'), 'afterScoring'));
    }

    public function testLargesseMakesEveryOtherPlayerDraw(): void
    {
        $state = $this->state([1 => ['Largesse']], deckNames: ['Apathy', 'Complacency', 'Creativity']);
        $state->startTurn(1);
        $this->play($state, 1, 'Largesse');

        self::assertCount(1, $state->hand(2));
        self::assertCount(1, $state->hand(3));
        self::assertCount(0, $state->hand(1));
    }

    public function testBelligerenceDiscardsOnlyOpposingMoodsOfTwoOrLess(): void
    {
        $state = $this->state([1 => ['Belligerence', 'Fear'], 2 => ['Creativity', 'Apathy', 'Benevolence']]);
        $state->startTurn(1);
        $this->seat($state, 1, 'Fear');
        $this->seat($state, 2, 'Creativity', 'Apathy', 'Benevolence');
        $this->play($state, 1, 'Belligerence', ['discard_qualifying_moods' => true]);

        self::assertTrue($state->isInPlay($this->id('Apathy')));
        self::assertFalse($state->isInPlay($this->id('Benevolence')));
        self::assertTrue($state->isInPlay($this->id('Belligerence')));
        self::assertCount(2, $state->discardPile());
    }

    public function testBelligerenceDoesNothingWhenDeclined(): void
    {
        $state = $this->state([1 => ['Belligerence'], 2 => ['Creativity']]);
        $state->startTurn(1);
        $this->seat($state, 2, 'Creativity');
        $this->play($state, 1, 'Belligerence');

        self::assertTrue($state->isInPlay($this->id('Creativity')));
    }

    public function testChagrinReturnsEveryOtherMoodWorthFourOrMoreToItsOwner(): void
    {
        $state = $this->state([1 => ['Chagrin', 'Apathy'], 2 => ['Complacency', 'Creativity']]);
        $state->startTurn(1);
        $this->seat($state, 1, 'Apathy');
        $this->seat($state, 2, 'Complacency', 'Creativity');
        $this->play($state, 1, 'Chagrin', ['return_qualifying_moods' => true]);

        self::assertContains($this->id('Apathy'), $state->hand(1));
        self::assertContains($this->id('Complacency'), $state->hand(2));
        self::assertTrue($state->isInPlay($this->id('Creativity')));
        self::assertTrue($state->isInPlay($this->id('Chagrin')));
    }

    public function testAtonementDiscardsOtherMoodsOfTheChosenColor(): void
    {
        $state = $this->state([1 => ['Atonement', 'Apathy'], 2 => ['Creativity', 'Complacency']]);
        $state->startTurn(1);
        $this->seat($state, 1, 'Apathy');
        $this->seat($state, 2, 'Creativity', 'Complacency');
        $this->play($state, 1, 'Atonement', ['color' => 'black']);

        self::assertTrue($state->isInPlay($this->id('Atonement')), 'Atonement itself is black but is an "other mood" exemption');
        self::assertFalse($state->isInPlay($this->id('Apathy')));
        self::assertTrue($state->isInPlay($this->id('Creativity')));
        self::assertTrue($state->isInPlay($this->id('Complacency')));
    }

    public function testAtonementRequiresAColor(): void
    {
        $state = $this->state([1 => ['Atonement']]);
        $state->startTurn(1);

        $this->expectException(InvalidChoiceException::class);
        $this->play($state, 1, 'Atonement');
    }

    public function testIntuitionDiscardsOtherMoodsWithTheChosenPrintedNumber(): void
    {
        $state = $this->state([1 => ['Intuition', 'Apathy'], 2 => ['Creativity', 'Complacency']]);
        $state->startTurn(1);
        $this->seat($state, 1, 'Apathy');
        $this->seat($state, 2, 'Creativity', 'Complacency');
        $this->play($state, 1, 'Intuition', ['value' => 4]);

        self::assertFalse($state->isInPlay($this->id('Apathy')));
        self::assertFalse($state->isInPlay($this->id('Complacency')));
        self::assertTrue($state->isInPlay($this->id('Creativity')));
        self::assertTrue($state->isInPlay($this->id('Intuition')));
    }

    public function testImpartialitySuppressesTheChosenParityUntilEndOfRound(): void
    {
        $state = $this->state([1 => ['Impartiality', 'Apathy'], 2 => ['Creativity', 'Charity']]);
        $state->startTurn(1);
        $this->seat($state, 1, 'Apathy'); // 4 even
        $this->seat($state, 2, 'Creativity', 'Charity'); // 0 even, 1 odd
        $this->play($state, 1, 'Impartiality', ['parity' => 'even']);

        self::assertTrue($state->isSuppressed($this->id('Apathy')));
        self::assertTrue($state->isSuppressed($this->id('Creativity')));
        self::assertFalse($state->isSuppressed($this->id('Charity')));
        self::assertFalse($state->isSuppressed($this->id('Impartiality')), 'Impartiality prints 2 (even) but is exempt as the source');
    }

    public function testReminiscencePutsADiscardOnTheBottomAndDraws(): void
    {
        $state = $this->state([1 => ['Reminiscence']], deckNames: ['Complacency'], discardNames: ['Apathy']);
        $state->startTurn(1);
        $this->play($state, 1, 'Reminiscence', ['discard_card_id' => $this->id('Apathy')]);

        self::assertSame([], $state->discardPile());
        self::assertSame($this->ids('Apathy'), array_slice($state->deck(), -1));
        self::assertSame($this->ids('Complacency'), $state->hand(1));
        self::assertSame(0, $state->playsRemaining());
    }

    public function testRemorseAlsoGrantsAnExtraPlay(): void
    {
        $state = $this->state([1 => ['Remorse']], deckNames: ['Complacency'], discardNames: ['Apathy']);
        $state->startTurn(1);
        $this->play($state, 1, 'Remorse', ['discard_card_id' => $this->id('Apathy')]);

        self::assertSame(1, $state->playsRemaining());
    }

    public function testLevityBottomDecksItselfAndDraws(): void
    {
        $state = $this->state([1 => ['Levity']], deckNames: ['Complacency']);
        $state->startTurn(1);
        $this->play($state, 1, 'Levity', ['bottom_deck_self' => true]);

        self::assertFalse($state->isInPlay($this->id('Levity')));
        self::assertSame($this->ids('Complacency'), $state->hand(1));
        self::assertSame($this->ids('Levity'), array_slice($state->deck(), -1));
        self::assertSame(1, $state->playsRemaining());
    }

    public function testLevityStaysWhenDeclined(): void
    {
        $state = $this->state([1 => ['Levity']], deckNames: ['Complacency']);
        $state->startTurn(1);
        $this->play($state, 1, 'Levity');

        self::assertTrue($state->isInPlay($this->id('Levity')));
        self::assertSame(1, $state->playsRemaining());
    }

    public function testDistrustReturnsARedOrGreenMoodToItsPlayersHand(): void
    {
        $state = $this->state([1 => ['Distrust'], 2 => ['Anger', 'Laziness']]);
        $state->startTurn(1);
        $this->seat($state, 2, 'Anger', 'Laziness'); // red, green
        $this->play($state, 1, 'Distrust', ['target_mood_id' => $this->id('Anger')]);

        self::assertFalse($state->isInPlay($this->id('Anger')));
        self::assertSame($this->ids('Anger'), $state->hand(2));
    }

    public function testDistrustRefusesATargetWithFewerThanTwoRedOrGreenMoods(): void
    {
        $state = $this->state([1 => ['Distrust'], 2 => ['Anger', 'Hate']]);
        $state->startTurn(1);
        $this->seat($state, 2, 'Anger', 'Hate'); // red, black

        $this->expectException(InvalidChoiceException::class);
        $this->play($state, 1, 'Distrust', ['target_mood_id' => $this->id('Anger')]);
    }

    public function testDisdainDiscardsAWhiteOrGreenMood(): void
    {
        $state = $this->state([1 => ['Disdain'], 2 => ['Complacency', 'Charity']]);
        $state->startTurn(1);
        $this->seat($state, 2, 'Complacency', 'Charity');
        $this->play($state, 1, 'Disdain', ['target_mood_id' => $this->id('Complacency')]);

        self::assertFalse($state->isInPlay($this->id('Complacency')));
        self::assertContains($this->id('Complacency'), $state->discardPile());
    }

    public function testIndignationRejectsATargetWorthMoreThanFour(): void
    {
        $state = $this->state([1 => ['Indignation'], 2 => ['Complacency', 'Bashfulness']]);
        $state->startTurn(1);
        $this->seat($state, 2, 'Complacency', 'Bashfulness'); // white 4, blue 6

        $this->expectException(InvalidChoiceException::class);
        $this->play($state, 1, 'Indignation', ['target_mood_id' => $this->id('Bashfulness')]);
    }

    public function testIndignationDiscardsATargetWorthFourOrLess(): void
    {
        $state = $this->state([1 => ['Indignation'], 2 => ['Complacency', 'Bashfulness']]);
        $state->startTurn(1);
        $this->seat($state, 2, 'Complacency', 'Bashfulness');
        $this->play($state, 1, 'Indignation', ['target_mood_id' => $this->id('Complacency')]);

        self::assertFalse($state->isInPlay($this->id('Complacency')));
    }

    public function testVigilanceSuppressesATargetOnlyWhileInPlay(): void
    {
        $state = $this->state([1 => ['Vigilance'], 2 => ['Apathy', 'Anger']]);
        $state->startTurn(1);
        $this->seat($state, 2, 'Apathy', 'Anger'); // black, red
        $this->play($state, 1, 'Vigilance', ['target_mood_id' => $this->id('Apathy')]);

        self::assertTrue($state->isSuppressed($this->id('Apathy')));

        $state->moveInPlayToDiscard($this->id('Vigilance'));
        self::assertFalse($state->isSuppressed($this->id('Apathy')));
    }

    public function testWarmthTakesADiscardPileCardWithTwoRedOrWhiteMoods(): void
    {
        $state = $this->state([1 => ['Warmth'], 2 => ['Anger', 'Complacency']], discardNames: ['Apathy']);
        $state->startTurn(1);
        $this->seat($state, 2, 'Anger', 'Complacency'); // red, white
        $this->play($state, 1, 'Warmth', ['discard_card_id' => $this->id('Apathy')]);

        self::assertSame($this->ids('Apathy'), $state->hand(1));
        self::assertSame([], $state->discardPile());
    }

    public function testWarmthDoesNothingWithoutTwoQualifyingMoods(): void
    {
        $state = $this->state([1 => ['Warmth'], 2 => ['Creativity']], discardNames: ['Apathy']);
        $state->startTurn(1);
        $this->seat($state, 2, 'Creativity');
        $this->play($state, 1, 'Warmth');

        self::assertSame([], $state->hand(1));
        self::assertSame($this->ids('Apathy'), $state->discardPile());
    }

    public function testYearningDiscardsACardToTakeOneFromTheDiscardPile(): void
    {
        $state = $this->state([1 => ['Yearning', 'Complacency'], 2 => ['Creativity', 'Anger']], discardNames: ['Apathy']);
        $state->startTurn(1);
        $this->seat($state, 2, 'Creativity', 'Anger'); // blue, red
        $this->play($state, 1, 'Yearning', [
            'hand_card_id' => $this->id('Complacency'),
            'discard_card_id' => $this->id('Apathy'),
        ]);

        self::assertSame($this->ids('Apathy'), $state->hand(1));
        self::assertSame($this->ids('Complacency'), $state->discardPile());
    }

    // ---- registry / schema consistency ----

    public function testEveryBatchCardHasARegisteredEffect(): void
    {
        $registry = DefaultEffectRegistry::build();
        foreach (self::$catalog as $id => $row) {
            if ($row['artSetCode'] !== 'XEMB') {
                continue;
            }
            self::assertTrue($registry->has($row['effectKey']), "{$row['effectKey']} has no registered effect");
        }
    }

    public function testChoiceSchemaExistsWhereTheEffectReadsChoices(): void
    {
        foreach (['belligerence', 'chagrin', 'atonement', 'intuition', 'impartiality', 'reminiscence', 'remorse', 'levity',
            'distrust', 'disdain', 'indignation', 'vigilance', 'warmth', 'yearning', 'rashness'] as $key) {
            self::assertNotEmpty(CardChoiceSchema::forEffectKey($key), "{$key} has no choice schema");
        }
    }
}
