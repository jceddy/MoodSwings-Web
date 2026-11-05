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

    public function testLongingDiscardsACardAndTakesUpToTwoFromTheDiscardPile(): void
    {
        $state = $this->state([1 => ['Longing', 'Complacency']], discardNames: ['Apathy', 'Courage', 'Boredom']);
        $state->startTurn(1);
        $this->play($state, 1, 'Longing', [
            'hand_card_id' => $this->id('Complacency'),
            'discard_card_ids' => $this->ids('Apathy', 'Courage'),
        ]);

        self::assertEqualsCanonicalizing($this->ids('Apathy', 'Courage'), $state->hand(1), 'a net +1 card in hand');
        self::assertEqualsCanonicalizing($this->ids('Boredom', 'Complacency'), $state->discardPile());
    }

    public function testLongingCanTakeBackTheCardItJustDiscarded(): void
    {
        $state = $this->state([1 => ['Longing', 'Complacency']]);
        $state->startTurn(1);
        $this->play($state, 1, 'Longing', [
            'hand_card_id' => $this->id('Complacency'),
            'discard_card_ids' => $this->ids('Complacency'),
        ]);

        self::assertSame($this->ids('Complacency'), $state->hand(1));
    }

    public function testLongingRefusesMoreThanTwoPickedCards(): void
    {
        $state = $this->state([1 => ['Longing', 'Complacency']], discardNames: ['Apathy', 'Courage', 'Boredom']);
        $state->startTurn(1);

        $this->expectException(InvalidChoiceException::class);
        $this->play($state, 1, 'Longing', [
            'hand_card_id' => $this->id('Complacency'),
            'discard_card_ids' => $this->ids('Apathy', 'Courage', 'Boredom'),
        ]);
    }

    public function testLongingIsOptional(): void
    {
        $state = $this->state([1 => ['Longing', 'Complacency']], discardNames: ['Apathy']);
        $state->startTurn(1);
        $this->play($state, 1, 'Longing');

        self::assertSame($this->ids('Complacency'), $state->hand(1));
        self::assertSame($this->ids('Apathy'), $state->discardPile());
    }

    public function testResignationPutsAHandCardOnTheBottomOfTheDeckAsItsCost(): void
    {
        $state = $this->state([1 => ['Resignation', 'Complacency']], ['Apathy']);
        $state->startTurn(1);
        $this->play($state, 1, 'Resignation', ['hand_card_id' => $this->id('Complacency')]);

        self::assertSame([], $state->hand(1));
        self::assertSame($this->ids('Apathy', 'Complacency'), $state->deck(), 'the paid card is on the bottom');
        self::assertTrue($state->isInPlay($this->id('Resignation')));
    }

    public function testResignationCannotBePlayedWithNoOtherCardInHand(): void
    {
        $state = $this->state([1 => ['Resignation']]);
        $state->startTurn(1);

        $this->expectException(IllegalPlayException::class);
        $this->play($state, 1, 'Resignation', []);
    }

    public function testVindictivenessTradesAMoodForALowerValueOne(): void
    {
        $state = $this->state([1 => ['Vindictiveness', 'Apathy'], 2 => ['Courage', 'Joy']]);
        $state->startTurn(1);
        $this->seat($state, 1, 'Apathy'); // 4
        $this->seat($state, 2, 'Courage', 'Joy'); // 1, 3
        $this->play($state, 1, 'Vindictiveness', [
            'discard_mood_id' => $this->id('Apathy'),
            'target_mood_id' => $this->id('Joy'),
        ]);

        self::assertFalse($state->isInPlay($this->id('Apathy')));
        self::assertFalse($state->isInPlay($this->id('Joy')));
        self::assertTrue($state->isInPlay($this->id('Courage')));
        self::assertTrue($state->isInPlay($this->id('Vindictiveness')));
    }

    public function testVindictivenessRefusesATargetThatIsNotLower(): void
    {
        $state = $this->state([1 => ['Vindictiveness', 'Apathy'], 2 => ['Boredom']]);
        $state->startTurn(1);
        $this->seat($state, 1, 'Apathy'); // 4
        $this->seat($state, 2, 'Boredom'); // 4: equal, not lower

        $this->expectException(InvalidChoiceException::class);
        $this->play($state, 1, 'Vindictiveness', [
            'discard_mood_id' => $this->id('Apathy'),
            'target_mood_id' => $this->id('Boredom'),
        ]);
    }

    public function testVindictivenessSecondStepIsOptionalAndNeedsTheFirst(): void
    {
        $state = $this->state([1 => ['Vindictiveness', 'Apathy'], 2 => ['Courage']]);
        $state->startTurn(1);
        $this->seat($state, 1, 'Apathy');
        $this->seat($state, 2, 'Courage');
        $this->play($state, 1, 'Vindictiveness', ['discard_mood_id' => $this->id('Apathy')]);
        self::assertFalse($state->isInPlay($this->id('Apathy')));
        self::assertTrue($state->isInPlay($this->id('Courage')), 'sacrificing without a second target is allowed');

        $second = $this->state([1 => ['Vindictiveness'], 2 => ['Courage']]);
        $second->startTurn(1);
        $this->seat($second, 2, 'Courage');
        $this->expectException(InvalidChoiceException::class);
        $this->play($second, 1, 'Vindictiveness', ['target_mood_id' => $this->id('Courage')]);
    }

    public function testVindictivenessCannotSacrificeItself(): void
    {
        $state = $this->state([1 => ['Vindictiveness']]);
        $state->startTurn(1);

        $this->expectException(InvalidChoiceException::class);
        $this->play($state, 1, 'Vindictiveness', ['discard_mood_id' => $this->id('Vindictiveness')]);
    }

    public function testMalevolenceRemovesAMoodOfTheChosenParityFromEachChosenPlayer(): void
    {
        $state = $this->state([1 => ['Malevolence'], 2 => ['Courage', 'Apathy'], 3 => ['Joy']]);
        $state->startTurn(1);
        $this->seat($state, 2, 'Courage', 'Apathy'); // printed 1, 4
        $this->seat($state, 3, 'Joy'); // printed 3
        $this->play($state, 1, 'Malevolence', [
            'parity' => 'odd',
            'target_mood_ids' => $this->ids('Courage', 'Joy'),
        ]);

        self::assertFalse($state->isInPlay($this->id('Courage')));
        self::assertFalse($state->isInPlay($this->id('Joy')));
        self::assertTrue($state->isInPlay($this->id('Apathy')), 'an even mood is left alone');
    }

    public function testMalevolenceRefusesAMoodOfTheOtherParity(): void
    {
        $state = $this->state([1 => ['Malevolence'], 2 => ['Apathy']]);
        $state->startTurn(1);
        $this->seat($state, 2, 'Apathy'); // 4 is even

        $this->expectException(InvalidChoiceException::class);
        $this->play($state, 1, 'Malevolence', ['parity' => 'odd', 'target_mood_ids' => $this->ids('Apathy')]);
    }

    public function testMalevolenceTakesZeroAsEvenAndOneMoodPerPlayer(): void
    {
        $state = $this->state([1 => ['Malevolence'], 2 => ['Anger', 'Apathy']]);
        $state->startTurn(1);
        $this->seat($state, 2, 'Anger', 'Apathy'); // 0 and 4, both even

        $this->expectException(InvalidChoiceException::class);
        $this->play($state, 1, 'Malevolence', ['parity' => 'even', 'target_mood_ids' => $this->ids('Anger', 'Apathy')]);
    }

    public function testRestlessnessNeedsThreeDifferentValuesAmongYourMoods(): void
    {
        $state = $this->state([1 => ['Restlessness', 'Courage', 'Apathy'], 2 => ['Anger', 'Boredom']]);
        $state->startTurn(1);
        $this->seat($state, 1, 'Restlessness', 'Courage'); // itself counts as its printed 3, Courage is 1
        $this->seat($state, 2, 'Anger', 'Boredom'); // opposing values never count
        self::assertSame(3, $state->valueOf($this->id('Restlessness')), 'only two different values');

        $this->seat($state, 1, 'Apathy'); // 4: three different values now
        self::assertSame(6, $state->valueOf($this->id('Restlessness')));
    }

    public function testRestlessnessDoesNotCountRepeatedValuesAsDifferent(): void
    {
        $state = $this->state([1 => ['Restlessness', 'Courage', 'Joy']]);
        $state->startTurn(1);
        $this->seat($state, 1, 'Restlessness', 'Courage', 'Joy'); // 3, 1, 3: Joy repeats Restlessness's 3

        self::assertSame(3, $state->valueOf($this->id('Restlessness')));
    }

    public function testUrgencyTurnsOnWithASecondPlayAfterItEnters(): void
    {
        $state = $this->state([1 => ['Urgency', 'Anger']]);
        $state->startRound(1, 1);
        $state->startTurn(1);
        $this->play($state, 1, 'Urgency');
        self::assertSame(3, $state->valueOf($this->id('Urgency')), 'its own play is one play');

        $state->grantExtraPlay();
        $this->play($state, 1, 'Anger');
        self::assertSame(6, $state->valueOf($this->id('Urgency')));
    }

    public function testUrgencyDoesNotCountPlaysFromBeforeItEntered(): void
    {
        $state = $this->state([1 => ['Anger', 'Urgency', 'Boredom']]);
        $state->startRound(1, 1);
        $state->startTurn(1);
        $state->grantExtraPlay();
        $state->grantExtraPlay();
        $this->play($state, 1, 'Anger');
        $this->play($state, 1, 'Urgency');
        self::assertSame(3, $state->valueOf($this->id('Urgency')), 'the earlier Anger does not count');

        $this->play($state, 1, 'Boredom');
        self::assertSame(6, $state->valueOf($this->id('Urgency')));
    }

    public function testUrgencyIgnoresOtherPlayersPlaysAndStartsOverEachRound(): void
    {
        $state = $this->state([1 => ['Urgency'], 2 => ['Anger']]);
        $state->startRound(1, 1);
        $state->startTurn(1);
        $this->play($state, 1, 'Urgency');
        $state->startTurn(2);
        $this->play($state, 2, 'Anger');
        self::assertSame(3, $state->valueOf($this->id('Urgency')), "an opponent's play isn't yours");

        $second = $this->state([1 => ['Urgency', 'Anger']]);
        $second->startRound(1, 1);
        $second->startTurn(1);
        $this->play($second, 1, 'Urgency');
        $second->grantExtraPlay();
        $this->play($second, 1, 'Anger');
        self::assertSame(6, $second->valueOf($this->id('Urgency')));
        $second->startRound(1, 2);
        self::assertSame(3, $second->valueOf($this->id('Urgency')), 'the tally is for the round it was made in');
    }

    // ---- static value modifiers ----

    public function testArdorAddsTheNumberOfRedMoodsToEachOwnRedMood(): void
    {
        $state = $this->state([1 => ['Ardor', 'Anger', 'Creativity'], 2 => ['Boredom']]);
        $state->startTurn(1);
        $this->seat($state, 1, 'Ardor', 'Anger', 'Creativity');
        $this->seat($state, 2, 'Boredom');

        // Ardor and Anger are red (0 + 2 each); Creativity is blue; the opponent's red mood isn't counted or boosted.
        self::assertSame(2, $state->valueOf($this->id('Ardor')));
        self::assertSame(2, $state->valueOf($this->id('Anger')));
        self::assertSame(0, $state->valueOf($this->id('Creativity')));
        self::assertSame(4, $state->valueOf($this->id('Boredom')));
    }

    public function testHumilityBoostsOwnMoodsPrintedZeroToTwo(): void
    {
        $state = $this->state([1 => ['Humility', 'Creativity', 'Apathy', 'Benevolence'], 2 => ['Charity']]);
        $state->startTurn(1);
        $this->seat($state, 1, 'Humility', 'Creativity', 'Apathy', 'Benevolence');
        $this->seat($state, 2, 'Charity');

        self::assertSame(3, $state->valueOf($this->id('Humility')), 'printed 3: not boosted');
        self::assertSame(1, $state->valueOf($this->id('Creativity')));
        self::assertSame(4, $state->valueOf($this->id('Apathy')));
        self::assertSame(3, $state->valueOf($this->id('Benevolence')));
        self::assertSame(1, $state->valueOf($this->id('Charity')), "an opponent's mood isn't boosted");
    }

    public function testKinshipBoostsMoodsSharingAColorWithAnotherOwnMood(): void
    {
        $state = $this->state([1 => ['Kinship', 'Apathy', 'Hate', 'Creativity']]);
        $state->startTurn(1);
        $this->seat($state, 1, 'Kinship', 'Apathy', 'Hate', 'Creativity');

        self::assertSame(1, $state->valueOf($this->id('Kinship')), 'the only green mood');
        self::assertSame(5, $state->valueOf($this->id('Apathy')), 'black, shares with Hate');
        self::assertSame(1, $state->valueOf($this->id('Hate')));
        self::assertSame(0, $state->valueOf($this->id('Creativity')), 'the only blue mood');
    }

    public function testOrderlinessBoostsOtherOwnMoodsOfTheChosenParity(): void
    {
        $state = $this->state([1 => ['Orderliness', 'Apathy', 'Charity', 'Creativity']]);
        $state->startTurn(1);
        $this->seat($state, 1, 'Apathy', 'Charity', 'Creativity');
        $this->play($state, 1, 'Orderliness', ['parity' => 'even']);

        self::assertSame(5, $state->valueOf($this->id('Apathy')));
        self::assertSame(1, $state->valueOf($this->id('Charity')), 'odd: untouched');
        self::assertSame(1, $state->valueOf($this->id('Creativity')), '0 is even');
        self::assertSame(2, $state->valueOf($this->id('Orderliness')), 'it only boosts its other moods');
    }

    public function testOrderlinessRequiresAChoice(): void
    {
        $state = $this->state([1 => ['Orderliness']]);
        $state->startTurn(1);

        $this->expectException(InvalidChoiceException::class);
        $this->play($state, 1, 'Orderliness');
    }

    public function testModifiersStackAndSuppressionOnlyZeroesTheSourcesOwnValue(): void
    {
        $state = $this->state([1 => ['Humility', 'Orderliness', 'Creativity']]);
        $state->startTurn(1);
        $this->seat($state, 1, 'Humility', 'Creativity', 'Orderliness');
        $state->setEffectState($this->id('Orderliness'), 'orderlinessParity', 'even');

        self::assertSame(2, $state->valueOf($this->id('Creativity')), 'Humility +1 and Orderliness +1 stack on 0');
        $state->suppress($this->id('Humility'), 'end_of_round');
        self::assertSame(0, $state->valueOf($this->id('Humility')), 'a suppressed mood is worth 0 itself');
        self::assertSame(2, $state->valueOf($this->id('Creativity')), 'but, like every while-in-play ability, it keeps applying');
    }

    public function testVerveOnlyBoostsRedMoodsPlayedThisRound(): void
    {
        $state = $this->state([1 => ['Verve', 'Anger', 'Boredom']]);
        $state->startRound(1, 2);
        $state->startTurn(1);
        $this->seat($state, 1, 'Verve');
        $this->seat($state, 1, 'Boredom');
        $state->setEffectState($this->id('Boredom'), 'playedInRound', 1); // an earlier round
        $state->setEffectState($this->id('Boredom'), 'playedByPlayerId', 1);
        $this->seat($state, 1, 'Anger');
        $state->setEffectState($this->id('Anger'), 'playedInRound', 2);
        $state->setEffectState($this->id('Anger'), 'playedByPlayerId', 1);

        self::assertSame(2, $state->valueOf($this->id('Anger')));
        self::assertSame(4, $state->valueOf($this->id('Boredom')));
    }

    public function testCompetitivenessIsOneMoreThanTheHighestOpposingMood(): void
    {
        $state = $this->state([1 => ['Competitiveness'], 2 => ['Apathy', 'Creativity']]);
        $state->startTurn(1);
        $this->seat($state, 1, 'Competitiveness');
        self::assertSame(1, $state->valueOf($this->id('Competitiveness')), 'no opposing moods');

        $this->seat($state, 2, 'Apathy', 'Creativity');
        self::assertSame(5, $state->valueOf($this->id('Competitiveness')));
    }

    public function testCompetitivenessIgnoresItsOwnersMoods(): void
    {
        $state = $this->state([1 => ['Competitiveness', 'Apathy'], 2 => ['Creativity']]);
        $state->startTurn(1);
        $this->seat($state, 1, 'Competitiveness', 'Apathy');
        $this->seat($state, 2, 'Creativity');

        self::assertSame(1, $state->valueOf($this->id('Competitiveness')));
    }

    public function testVitalitysColorBonusIsKeptByPlayerAndColor(): void
    {
        $state = $this->state([1 => ['Anger', 'Boredom', 'Creativity'], 2 => ['Hate']]);
        $state->startTurn(1);
        $this->seat($state, 1, 'Anger', 'Creativity');
        $state->addRoundColorBonus(1, 'red', 2);
        $this->seat($state, 1, 'Boredom'); // played after the bonus was chosen

        self::assertSame(2, $state->valueOf($this->id('Anger')));
        self::assertSame(6, $state->valueOf($this->id('Boredom')));
        self::assertSame(0, $state->valueOf($this->id('Creativity')), 'another color');

        $state->addRoundColorBonus(1, 'red', 2);
        self::assertSame(4, $state->valueOf($this->id('Anger')), 'two bonuses stack');
        $state->clearEndOfRoundValueBonuses();
        self::assertSame(0, $state->valueOf($this->id('Anger')));
    }

    // ---- deck-top moves and hand reveals ----

    public function testCarelessnessPutsTheTopTwoCardsOfTheDeckIntoTheDiscardPile(): void
    {
        $state = $this->state([1 => ['Carelessness']], deckNames: ['Apathy', 'Complacency', 'Creativity']);
        $state->startTurn(1);
        $this->play($state, 1, 'Carelessness');

        self::assertSame($this->ids('Apathy', 'Complacency'), $state->discardPile());
        self::assertSame($this->ids('Creativity'), $state->deck());
    }

    public function testCarelessnessWithAShortDeckMovesWhatThereIs(): void
    {
        $state = $this->state([1 => ['Carelessness']], deckNames: ['Apathy']);
        $state->startTurn(1);
        $this->play($state, 1, 'Carelessness');

        self::assertSame($this->ids('Apathy'), $state->discardPile());
    }

    public function testHonestyBecomesSixWhenEveryHandCardSharesAColorWithAMood(): void
    {
        // Honesty is green and counts as a mood: green Laziness shares, black Apathy shares with the black mood.
        $state = $this->state([1 => ['Honesty', 'Laziness', 'Apathy', 'Hate']]);
        $state->startTurn(1);
        $this->seat($state, 1, 'Hate'); // black
        $this->play($state, 1, 'Honesty');

        self::assertSame(6, $state->valueOf($this->id('Honesty')));
        self::assertEqualsCanonicalizing($this->ids('Laziness', 'Apathy'), $state->consumeRevealedCardIds(), 'the whole hand was revealed');
    }

    public function testHonestyStaysAtItsPrintedValueWhenAHandCardSharesNoColor(): void
    {
        $state = $this->state([1 => ['Honesty', 'Laziness', 'Creativity']]); // blue Creativity: no blue mood
        $state->startTurn(1);
        $this->play($state, 1, 'Honesty');

        self::assertSame(3, $state->valueOf($this->id('Honesty')));
    }

    public function testHonestyWithAnEmptyHandCounts(): void
    {
        $state = $this->state([1 => ['Honesty']]);
        $state->startTurn(1);
        $this->play($state, 1, 'Honesty');

        self::assertSame(6, $state->valueOf($this->id('Honesty')));
    }

    public function testWhimsyRevealsARandomHandCardAndGrantsAPlayOfOnlyThatCard(): void
    {
        $state = $this->state([1 => ['Whimsy', 'Apathy']]);
        $state->startTurn(1);
        $this->play($state, 1, 'Whimsy');

        self::assertSame($this->ids('Apathy'), $state->consumeRevealedCardIds());
        self::assertSame(1, $state->playsRemaining());
        $this->play($state, 1, 'Apathy');
        self::assertTrue($state->isInPlay($this->id('Apathy')));
    }

    public function testWhimsyRefusesAnyOtherCardForItsExtraPlay(): void
    {
        $state = $this->state([1 => ['Whimsy', 'Apathy', 'Complacency', 'Creativity']]);
        $state->startTurn(1);
        $this->play($state, 1, 'Whimsy');
        $revealed = $state->consumeRevealedCardIds()[0];

        $other = null;
        foreach ($this->ids('Apathy', 'Complacency', 'Creativity') as $candidate) {
            if ($candidate !== $revealed) {
                $other = $candidate;
                break;
            }
        }
        $this->expectException(IllegalPlayException::class);
        $this->plays->playMood($state, 1, $other, new PlayerChoices([]));
    }

    public function testWhimsyWithAnEmptyHandDoesNothing(): void
    {
        $state = $this->state([1 => ['Whimsy']]);
        $state->startTurn(1);
        $this->play($state, 1, 'Whimsy');

        self::assertSame(0, $state->playsRemaining());
    }

    public function testDiscernmentCyclesOnlyTheChosenParityAndDrawsThatMany(): void
    {
        // Apathy 4 (even), Charity 1 (odd), Creativity 0 (even).
        $state = $this->state([1 => ['Discernment', 'Apathy', 'Charity', 'Creativity']], deckNames: ['Complacency', 'Courage']);
        $state->startTurn(1);
        $this->play($state, 1, 'Discernment', ['parity' => 'even', 'reveal_card_ids' => $this->ids('Apathy', 'Creativity')]);

        self::assertEqualsCanonicalizing($this->ids('Charity', 'Complacency', 'Courage'), $state->hand(1));
        self::assertSame($this->ids('Apathy', 'Creativity'), $state->deck());
    }

    public function testDiscernmentRejectsACardOfTheWrongParity(): void
    {
        $state = $this->state([1 => ['Discernment', 'Charity']]);
        $state->startTurn(1);

        $this->expectException(InvalidChoiceException::class);
        $this->play($state, 1, 'Discernment', ['parity' => 'even', 'reveal_card_ids' => $this->ids('Charity')]);
    }

    public function testDiscernmentIsOptionalBeyondTheParity(): void
    {
        $state = $this->state([1 => ['Discernment', 'Apathy']], deckNames: ['Complacency']);
        $state->startTurn(1);
        $this->play($state, 1, 'Discernment', ['parity' => 'odd']);

        self::assertSame($this->ids('Apathy'), $state->hand(1));
    }

    public function testTurmoilForcesTheChosenPlayerToCycleEveryCardOfThatParity(): void
    {
        $state = $this->state([1 => ['Turmoil'], 2 => ['Apathy', 'Charity', 'Creativity']], deckNames: ['Complacency', 'Courage']);
        $state->startTurn(1);
        $this->play($state, 1, 'Turmoil', ['parity' => 'even', 'target_player_id' => 2]);

        self::assertEqualsCanonicalizing($this->ids('Charity', 'Complacency', 'Courage'), $state->hand(2));
        self::assertEqualsCanonicalizing($this->ids('Apathy', 'Charity', 'Creativity'), $state->consumeRevealedCardIds(), "the chosen player's whole hand is revealed");
    }

    public function testTurmoilCanTargetYourself(): void
    {
        $state = $this->state([1 => ['Turmoil', 'Apathy']], deckNames: ['Complacency']);
        $state->startTurn(1);
        $this->play($state, 1, 'Turmoil', ['parity' => 'even', 'target_player_id' => 1]);

        self::assertSame($this->ids('Complacency'), $state->hand(1));
    }

    // ---- colorless ----

    public function testAColorlessGateCardNeedsAMoodOfItsColorOfYourOwn(): void
    {
        $state = $this->state([1 => ['Reverence', 'Anger'], 2 => ['Creativity']]);
        $state->startTurn(1);
        $this->seat($state, 2, 'Creativity');
        $this->seat($state, 1, 'Anger'); // red -- Reverence wants white

        $this->expectException(IllegalPlayException::class);
        $this->play($state, 1, 'Reverence');
    }

    public function testAnOpponentsMoodOfTheColorDoesNotOpenTheGate(): void
    {
        $state = $this->state([1 => ['Detachment'], 2 => ['Creativity']]); // Creativity is blue
        $state->startTurn(1);
        $this->seat($state, 2, 'Creativity');

        $this->expectException(IllegalPlayException::class);
        $this->play($state, 1, 'Detachment');
    }

    public function testAColorlessGateCardPlaysOnceYouHaveAMoodOfItsColor(): void
    {
        $state = $this->state([1 => ['Resentment', 'Hate']]);
        $state->startTurn(1);
        $this->seat($state, 1, 'Hate'); // black
        $this->play($state, 1, 'Resentment');

        self::assertTrue($state->isInPlay($this->id('Resentment')));
        self::assertSame('colorless', $state->colorOf($this->id('Resentment')));
        self::assertSame(5, $state->valueOf($this->id('Resentment')));
    }

    public function testASuppressedMoodStillOpensTheGate(): void
    {
        $state = $this->state([1 => ['Impatience', 'Anger']]);
        $state->startTurn(1);
        $this->seat($state, 1, 'Anger');
        $state->suppress($this->id('Anger'), 'end_of_round');
        $this->play($state, 1, 'Impatience');

        self::assertTrue($state->isInPlay($this->id('Impatience')));
    }

    public function testEveryGateCardChecksItsOwnColor(): void
    {
        foreach (['Reverence' => 'Charity', 'Detachment' => 'Creativity', 'Resentment' => 'Apathy', 'Impatience' => 'Anger', 'Contentment' => 'Laziness'] as $gate => $enabler) {
            $state = $this->state([1 => [$gate, $enabler]]);
            $state->startTurn(1);
            $this->seat($state, 1, $enabler);
            $this->play($state, 1, $gate);
            self::assertTrue($state->isInPlay($this->id($gate)), "{$gate} should play with a {$enabler}");
        }
    }

    public function testEmptinessCountsTheColorsAmongAllMoodsButNotColorless(): void
    {
        $state = $this->state([1 => ['Emptiness', 'Creativity', 'Apathy', 'Reverence'], 2 => ['Anger', 'Hate']]);
        $state->startTurn(1);
        $this->seat($state, 1, 'Emptiness', 'Creativity', 'Apathy', 'Reverence'); // blue, black (two colorless)
        self::assertSame(1 + 2, $state->valueOf($this->id('Emptiness')));

        $this->seat($state, 2, 'Anger', 'Hate'); // red, black (black already counted)
        self::assertSame(1 + 3, $state->valueOf($this->id('Emptiness')), "an opponent's colors count");
    }

    public function testNeutralityGainsTwoPerOtherColorlessMood(): void
    {
        $state = $this->state([1 => ['Neutrality', 'Reverence', 'Apathy'], 2 => ['Detachment']]);
        $state->startTurn(1);
        $this->seat($state, 1, 'Neutrality', 'Reverence', 'Apathy');
        $this->seat($state, 2, 'Detachment');

        self::assertSame(1 + 2 * 2, $state->valueOf($this->id('Neutrality')), "Reverence and the opponent's Detachment; not itself, not Apathy");
    }

    public function testUnderImaginationEveryMoodHasAColorSoColorlessCardsStopCounting(): void
    {
        $state = $this->state([1 => ['Neutrality', 'Reverence', 'Imagination']]);
        $state->startTurn(1);
        $this->seat($state, 1, 'Neutrality', 'Reverence', 'Imagination');
        self::assertSame(1 + 2, $state->valueOf($this->id('Neutrality')));
        $state->setEffectState($this->id('Imagination'), 'color', 'green');

        self::assertSame('green', $state->colorOf($this->id('Reverence')));
        self::assertSame(1, $state->valueOf($this->id('Neutrality')));
    }

    public function testColorlessMoodsShareNoColorSoKinshipIgnoresThem(): void
    {
        $state = $this->state([1 => ['Kinship', 'Reverence', 'Detachment']]);
        $state->startTurn(1);
        $this->seat($state, 1, 'Kinship', 'Reverence', 'Detachment');

        self::assertSame(5, $state->valueOf($this->id('Reverence')), 'two colorless moods do not share a color');
    }

    public function testAColorlessMoodNeverCountsAsAColorForCelebration(): void
    {
        $state = $this->state([1 => ['Celebration', 'Reverence'], 2 => ['Anger']]);
        $state->startTurn(1);
        $this->seat($state, 1, 'Celebration', 'Reverence'); // green + colorless = 1 color
        $this->seat($state, 2, 'Anger'); // 1 color: not fewer than ours

        self::assertSame(3, $state->valueOf($this->id('Celebration')), 'a colorless mood is not a second color');
    }

    public function testUnityIsTrueWithFiveMoodsOfOneColorAndNeverCountsItself(): void
    {
        $effect = DefaultEffectRegistry::build()->for('unity');
        $reds = array_values(array_filter(array_keys(self::$idByName), fn (string $n) => self::$catalog[self::$idByName[$n]]['color'] === 'red'));
        $five = array_slice($reds, 0, 5);
        $state = $this->state([1 => array_merge(['Unity'], $five)]);
        $state->startTurn(1);
        $this->seat($state, 1, 'Unity', ...array_slice($five, 0, 4));
        self::assertFalse($effect->winsRoundAfterScoring($state, $this->id('Unity'), 1), 'four red moods, and Unity is colorless');

        $this->seat($state, 1, $five[4]);
        self::assertTrue($effect->winsRoundAfterScoring($state, $this->id('Unity'), 1));
    }

    // ---- extra plays and "each time you play another mood" reactions ----

    public function testFamiliarityOnlyAllowsAnExtraMoodWithTheSameNumberAsOneOfYourMoods(): void
    {
        $state = $this->state([1 => ['Familiarity', 'Complacency', 'Creativity', 'Apathy']]);
        $state->startTurn(1);
        $this->seat($state, 1, 'Apathy'); // printed 4
        $this->play($state, 1, 'Familiarity'); // printed 2
        self::assertSame(1, $state->playsRemaining());

        try {
            $this->play($state, 1, 'Creativity'); // printed 0: matches nothing
            self::fail('A non-matching card was playable with the grant');
        } catch (IllegalPlayException) {
            self::assertTrue(true);
        }
        $this->play($state, 1, 'Complacency'); // printed 4, like Apathy
        self::assertTrue($state->isInPlay($this->id('Complacency')));
    }

    public function testImpulseTagsItselfToReturnToHandOnlyOnceItsExtraPlayIsUsed(): void
    {
        $unused = $this->state([1 => ['Impulse', 'Apathy']]);
        $unused->startTurn(1);
        $this->play($unused, 1, 'Impulse');
        self::assertNull($unused->effectState($this->id('Impulse'), 'afterScoring'), 'no tag until the extra play is spent');

        $used = $this->state([1 => ['Impulse', 'Apathy']]);
        $used->startTurn(1);
        $this->play($used, 1, 'Impulse');
        $this->play($used, 1, 'Apathy');
        self::assertSame(['action' => 'return_to_hand', 'condition' => 'always', 'playerId' => 1], $used->effectState($this->id('Impulse'), 'afterScoring'));
        self::assertNull($used->effectState($this->id('Apathy'), 'afterScoring'), 'the extra mood itself is not tagged');
    }

    public function testWarinessGivesACardAndGrantsAnExtraPlayWithTwoBlueOrBlackMoods(): void
    {
        $state = $this->state([1 => ['Wariness', 'Apathy'], 2 => ['Creativity', 'Hate']]);
        $state->startTurn(1);
        $this->seat($state, 2, 'Creativity', 'Hate'); // blue, black
        $this->play($state, 1, 'Wariness', ['give_card_id' => $this->id('Apathy'), 'recipient_player_id' => 2]);

        self::assertContains($this->id('Apathy'), $state->hand(2));
        self::assertSame(1, $state->playsRemaining());
    }

    public function testWarinessRefusesToGiveWithFewerThanTwoBlueOrBlackMoods(): void
    {
        $state = $this->state([1 => ['Wariness', 'Apathy'], 2 => ['Creativity']]);
        $state->startTurn(1);
        $this->seat($state, 2, 'Creativity');

        $this->expectException(InvalidChoiceException::class);
        $this->play($state, 1, 'Wariness', ['give_card_id' => $this->id('Apathy'), 'recipient_player_id' => 2]);
    }

    public function testWarinessIsOptional(): void
    {
        $state = $this->state([1 => ['Wariness']]);
        $state->startTurn(1);
        $this->play($state, 1, 'Wariness');

        self::assertSame(0, $state->playsRemaining());
    }

    public function testAdrenalineGrantsAnExtraPlayOnTheFirstRedMoodEachTurnOnly(): void
    {
        $state = $this->state([1 => ['Adrenaline', 'Anger', 'Boredom', 'Apathy']]);
        $state->startTurn(1);
        $this->seat($state, 1, 'Adrenaline');

        $this->play($state, 1, 'Apathy'); // black: no trigger
        self::assertSame(0, $state->playsRemaining());

        $state = $this->state([1 => ['Adrenaline', 'Anger', 'Boredom']]);
        $state->startTurn(1);
        $this->seat($state, 1, 'Adrenaline');
        $this->play($state, 1, 'Anger');
        self::assertSame(1, $state->playsRemaining(), 'the first red mood grants a play');
        $this->play($state, 1, 'Boredom');
        self::assertSame(0, $state->playsRemaining(), 'a second red mood the same turn does not');
    }

    public function testAdrenalineFiresAgainOnceTheTurnResets(): void
    {
        $state = $this->state([1 => ['Adrenaline', 'Anger', 'Boredom']]);
        $state->startTurn(1);
        $this->seat($state, 1, 'Adrenaline');
        $this->play($state, 1, 'Anger');
        $state->resetTurnReactions();
        $state->startTurn(1);
        $this->play($state, 1, 'Boredom');

        self::assertSame(1, $state->playsRemaining(), 'a new turn: the first red mood grants a play again');
    }

    public function testAffinityTriggersOnAMoodWithTheSameNumberAsAnotherOfYours(): void
    {
        $state = $this->state([1 => ['Affinity', 'Apathy', 'Complacency', 'Creativity']]);
        $state->startTurn(1);
        $this->seat($state, 1, 'Affinity', 'Apathy'); // printed 1 and 4

        $this->play($state, 1, 'Creativity'); // printed 0: no match
        self::assertSame(0, $state->playsRemaining());

        $state = $this->state([1 => ['Affinity', 'Apathy', 'Complacency']]);
        $state->startTurn(1);
        $this->seat($state, 1, 'Affinity', 'Apathy');
        $this->play($state, 1, 'Complacency'); // printed 4, like Apathy
        self::assertSame(1, $state->playsRemaining());
    }

    public function testDominanceDiscardsAnOpposingMoodLowerThanTheRedMoodPlayed(): void
    {
        $state = $this->state([1 => ['Dominance', 'Boredom'], 2 => ['Creativity', 'Apathy']]);
        $state->startTurn(1);
        $this->seat($state, 1, 'Dominance');
        $this->seat($state, 2, 'Creativity', 'Apathy'); // 0 and 4
        $this->play($state, 1, 'Boredom', ['dominance_target_mood_id' => $this->id('Creativity')]); // Boredom is a red 4

        self::assertFalse($state->isInPlay($this->id('Creativity')));
        self::assertTrue($state->isInPlay($this->id('Apathy')));
    }

    public function testDominanceRefusesAMoodNotLowerThanTheRedMood(): void
    {
        $state = $this->state([1 => ['Dominance', 'Boredom'], 2 => ['Apathy']]);
        $state->startTurn(1);
        $this->seat($state, 1, 'Dominance');
        $this->seat($state, 2, 'Apathy'); // 4, the same as Boredom

        $this->expectException(InvalidChoiceException::class);
        $this->play($state, 1, 'Boredom', ['dominance_target_mood_id' => $this->id('Apathy')]);
    }

    public function testDominanceIgnoresAMoodThatIsNotRed(): void
    {
        $state = $this->state([1 => ['Dominance', 'Apathy'], 2 => ['Creativity']]);
        $state->startTurn(1);
        $this->seat($state, 1, 'Dominance');
        $this->seat($state, 2, 'Creativity');
        $this->play($state, 1, 'Apathy', ['dominance_target_mood_id' => $this->id('Creativity')]);

        self::assertTrue($state->isInPlay($this->id('Creativity')), 'Apathy is black, so Dominance does not react');
    }

    public function testReflectionLootsOnAnotherPlay(): void
    {
        $state = $this->state([1 => ['Reflection', 'Apathy', 'Complacency']], deckNames: ['Creativity']);
        $state->startTurn(1);
        $this->seat($state, 1, 'Reflection');
        $this->play($state, 1, 'Apathy', ['reflection_discard_card_id' => $this->id('Complacency')]);

        self::assertSame([$this->id('Complacency')], $state->discardPile());
        self::assertSame($this->ids('Creativity'), $state->hand(1));
    }

    public function testReflectionIsOptional(): void
    {
        $state = $this->state([1 => ['Reflection', 'Apathy', 'Complacency']], deckNames: ['Creativity']);
        $state->startTurn(1);
        $this->seat($state, 1, 'Reflection');
        $this->play($state, 1, 'Apathy');

        self::assertSame([], $state->discardPile());
    }

    public function testReverieCyclesADiscardCardWhenItIsPlayed(): void
    {
        $state = $this->state([1 => ['Reverie']], deckNames: ['Creativity', 'Courage'], discardNames: ['Complacency']);
        $state->startTurn(1);
        $this->play($state, 1, 'Reverie', ['discard_card_id' => $this->id('Complacency')]); // its own after-playing half

        self::assertSame([], $state->discardPile());
        self::assertSame($this->ids('Creativity'), $state->hand(1), 'drew a card');
        self::assertSame($this->ids('Complacency'), array_slice($state->deck(), -1));
    }

    public function testReverieReactionCyclesADiscardCardWhenAnotherMoodIsPlayed(): void
    {
        $state = $this->state([1 => ['Reverie', 'Apathy']], deckNames: ['Creativity'], discardNames: ['Complacency']);
        $state->startTurn(1);
        $this->seat($state, 1, 'Reverie');
        $this->play($state, 1, 'Apathy', ['reverie_discard_card_id' => $this->id('Complacency')]);

        self::assertSame([], $state->discardPile());
        self::assertSame($this->ids('Complacency'), array_slice($state->deck(), -1), 'on the bottom of the deck');
        self::assertSame($this->ids('Creativity'), $state->hand(1), 'and a card drawn');
    }

    // ---- Schadenfreude ----

    public function testSchadenfreudeIsSixOnceAnOpponentHasDiscardedFromHandThisRound(): void
    {
        $state = $this->state([1 => ['Schadenfreude'], 2 => ['Apathy']]);
        $state->startTurn(1);
        $this->seat($state, 1, 'Schadenfreude');
        self::assertSame(3, $state->valueOf($this->id('Schadenfreude')));

        $state->moveHandToDiscard(2, $this->id('Apathy'));
        self::assertSame(6, $state->valueOf($this->id('Schadenfreude')));
    }

    public function testSchadenfreudeCountsADiscardMadeBeforeItWasPlayed(): void
    {
        $state = $this->state([1 => ['Schadenfreude'], 2 => ['Apathy']]);
        $state->startTurn(1);
        $state->moveHandToDiscard(2, $this->id('Apathy'));
        $this->seat($state, 1, 'Schadenfreude');

        self::assertSame(6, $state->valueOf($this->id('Schadenfreude')));
    }

    public function testSchadenfreudeIgnoresYourOwnDiscards(): void
    {
        $state = $this->state([1 => ['Schadenfreude', 'Apathy'], 2 => []]);
        $state->startTurn(1);
        $this->seat($state, 1, 'Schadenfreude');
        $state->moveHandToDiscard(1, $this->id('Apathy'));

        self::assertSame(3, $state->valueOf($this->id('Schadenfreude')));
    }

    public function testSchadenfreudeIgnoresMoodsPutIntoTheDiscardPileAndDeckCards(): void
    {
        $state = $this->state([1 => ['Schadenfreude'], 2 => ['Creativity']], deckNames: ['Apathy']);
        $state->startTurn(1);
        $this->seat($state, 1, 'Schadenfreude');
        $this->seat($state, 2, 'Creativity');
        $state->moveInPlayToDiscard($this->id('Creativity')); // an opponent's mood, not a card from hand
        $state->moveDeckTopToDiscard(2); // a deck card, not a card from hand

        self::assertSame(3, $state->valueOf($this->id('Schadenfreude')));
    }

    public function testTheRoundsHandDiscardsCanBeResetAndRestored(): void
    {
        $state = $this->state([1 => [], 2 => ['Apathy']]);
        $state->moveHandToDiscard(2, $this->id('Apathy'));
        self::assertSame([2], $state->playersWhoDiscardedFromHandThisRound());
        $state->resetRoundHandDiscards();
        self::assertSame([], $state->playersWhoDiscardedFromHandThisRound());
        $state->restoreRoundHandDiscards([2, 3]);
        self::assertSame([2, 3], $state->playersWhoDiscardedFromHandThisRound());
    }

    // ---- extra scoring ----

    public function testFulfillmentScoresEachOwnMoodWorthSixOrMoreAnExtraTime(): void
    {
        $state = $this->state([1 => ['Fulfillment', 'Bashfulness', 'Apathy'], 2 => ['Neurosis']]);
        $state->startTurn(1);
        $this->seat($state, 1, 'Fulfillment', 'Bashfulness', 'Apathy'); // 1, 6, 4
        $this->seat($state, 2, 'Neurosis'); // 5 -- an opponent's mood never counts for us

        $scores = (new \MoodSwings\Rules\RoundScorer())->score($state);
        self::assertSame(1 + 6 + 4 + 6, $scores[1], 'Bashfulness (6) scores twice');
        self::assertSame(5, $scores[2]);
    }

    public function testGratitudeScoresEachOwnMoodWorthTwoOrLessAnExtraTime(): void
    {
        $state = $this->state([1 => ['Gratitude', 'Benevolence', 'Charity', 'Apathy']]);
        $state->startTurn(1);
        $this->seat($state, 1, 'Gratitude', 'Benevolence', 'Charity', 'Apathy'); // 0, 2, 1, 4

        $scores = (new \MoodSwings\Rules\RoundScorer())->score($state);
        self::assertSame(0 + 2 + 1 + 4 + (0 + 2 + 1), $scores[1]);
    }

    public function testGratitudeReadsCurrentValueNotPrintedNumber(): void
    {
        $state = $this->state([1 => ['Gratitude', 'Benevolence']]);
        $state->startTurn(1);
        $this->seat($state, 1, 'Gratitude', 'Benevolence');
        $state->addRoundColorBonus(1, 'white', 1); // Benevolence is white: 2 -> 3, no longer "2 or less"

        $scores = (new \MoodSwings\Rules\RoundScorer())->score($state);
        self::assertSame(0 + 3, $scores[1]);
    }

    // ---- round-win counts ----

    public function testResolveIsSixOnlyWhileYouHaveWonFewerRoundsThanEveryOtherPlayer(): void
    {
        $state = $this->state([1 => ['Resolve']]);
        $state->startTurn(1);
        $this->seat($state, 1, 'Resolve');

        $state->setRoundWins([1 => 0, 2 => 0, 3 => 0]);
        self::assertSame(3, $state->valueOf($this->id('Resolve')), 'tied for fewest is not fewer than each other player');
        $state->setRoundWins([1 => 0, 2 => 1, 3 => 0]);
        self::assertSame(3, $state->valueOf($this->id('Resolve')), 'still tied with one player');
        $state->setRoundWins([1 => 0, 2 => 1, 3 => 2]);
        self::assertSame(6, $state->valueOf($this->id('Resolve')));
        $state->setRoundWins([1 => 2, 2 => 1, 3 => 2]);
        self::assertSame(3, $state->valueOf($this->id('Resolve')));
    }

    public function testVindicationGrowsByTwoPerRoundWon(): void
    {
        $state = $this->state([1 => ['Vindication']]);
        $state->startTurn(1);
        $this->seat($state, 1, 'Vindication');

        self::assertSame(2, $state->valueOf($this->id('Vindication')));
        $state->setRoundWins([1 => 3, 2 => 1]);
        self::assertSame(8, $state->valueOf($this->id('Vindication')));
    }

    public function testPerseveranceGrowsPermanentlyOnlyWhileBehindEveryoneElse(): void
    {
        $state = $this->state([1 => ['Perseverance']]);
        $state->startTurn(1);
        $this->seat($state, 1, 'Perseverance');
        $effect = DefaultEffectRegistry::build()->for('perseverance');

        $state->setRoundWins([1 => 1, 2 => 1, 3 => 0]);
        $effect->afterScoring($state, $this->id('Perseverance'), 1);
        self::assertSame(2, $state->valueOf($this->id('Perseverance')), 'not behind everyone');

        $state->setRoundWins([1 => 0, 2 => 1, 3 => 1]);
        $effect->afterScoring($state, $this->id('Perseverance'), 1);
        $effect->afterScoring($state, $this->id('Perseverance'), 1);
        self::assertSame(6, $state->valueOf($this->id('Perseverance')), '+2 each time, and it stays');
        $state->setRoundWins([1 => 5, 2 => 1, 3 => 1]);
        self::assertSame(6, $state->valueOf($this->id('Perseverance')), 'permanent');
    }

    /**
     * Two players each holding their own copy of the same catalog card needs distinct card instance ids
     * (the engine's ids are game_cards ids), which state() -- one id per catalog card -- can't express.
     */
    public function testTwoCompetitivenessMoodsIgnoreEachOtherAndSettleOnTheSameValue(): void
    {
        $competitiveness = $this->id('Competitiveness');
        $apathy = $this->id('Apathy');
        $catalog = self::$catalog;
        $instanceToCatalog = [1001 => $competitiveness, 1002 => $competitiveness, 1003 => $apathy];
        foreach ($instanceToCatalog as $instanceId => $catalogId) {
            $catalog[$instanceId] = self::$catalog[$catalogId];
        }
        $build = fn (array $hands): BoardState => new BoardState(
            $catalog, DefaultEffectRegistry::build(), [1, 2], $hands, [], [], false, [], $instanceToCatalog,
        );

        $state = $build([1 => [1001], 2 => [1002]]);
        $state->startTurn(1);
        $state->moveHandToInPlay(1, 1001);
        $state->moveHandToInPlay(2, 1002);
        self::assertSame(1, $state->valueOf(1001), 'no opposing moods without the ability: worth 1');
        self::assertSame(1, $state->valueOf(1002));

        $state = $build([1 => [1001, 1003], 2 => [1002]]);
        $state->startTurn(1);
        $state->moveHandToInPlay(1, 1001);
        $state->moveHandToInPlay(1, 1003);
        $state->moveHandToInPlay(2, 1002);
        self::assertSame(1, $state->valueOf(1001), "P1's own Apathy is not an opposing mood for P1's copy");
        self::assertSame(5, $state->valueOf(1002), "P2's copy counts P1's Apathy (4) but not P1's copy");
        self::assertSame(4, $state->valueOf(1003));
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
