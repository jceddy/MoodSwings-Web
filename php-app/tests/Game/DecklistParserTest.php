<?php

declare(strict_types=1);

namespace MoodSwings\Tests\Game;

use MoodSwings\Game\DecklistParser;
use MoodSwings\Game\Exceptions\GameStateException;
use PHPUnit\Framework\TestCase;

final class DecklistParserTest extends TestCase
{
    private function parser(): DecklistParser
    {
        return new DecklistParser([
            'cheer' => 110,
            'indifference' => 44,
            'rage' => 98,
            'serenity' => 129,
            'harmony' => 123,
            'meekness' => 19,
            'doubt' => 36,
            'superiority' => 77,
            'laziness' => 126,
            'charity' => 3,
            'betrayal' => 56,
            'self-loathing' => 75,
            'kindness' => 17,
            'panic' => 48,
            'euphoria' => 117,
        ]);
    }

    public function testParsesTheExampleLearnerDeckFromTheIssueDescription(): void
    {
        $text = <<<'DECK'
            About
            Name Learner Deck

            Cheer
            Indifference
            Rage
            1 Serenity
            1 Harmony
            1 Meekness
            1 Doubt (MSW) 36
            1 Superiority (MSW) 77
            1 Laziness (MSW) 126
            Charity
            Betrayal
            Self-Loathing
            Kindness
            Panic
            Euphoria
            DECK;

        $result = $this->parser()->parse($text);

        self::assertSame('Learner Deck', $result['name']);
        self::assertCount(15, $result['cardIds']);
        self::assertSame(
            [110, 44, 98, 129, 123, 19, 36, 77, 126, 3, 56, 75, 17, 48, 117],
            $result['cardIds'],
        );
    }

    public function testNoAboutBlockLeavesNameNull(): void
    {
        $result = $this->parser()->parse("Charity\nKindness");

        self::assertNull($result['name']);
        self::assertSame([3, 17], $result['cardIds']);
    }

    public function testACountPrefixExpandsToThatManyCopies(): void
    {
        $result = $this->parser()->parse('3 Charity');

        self::assertSame([3, 3, 3], $result['cardIds']);
    }

    public function testCardNameMatchingIsCaseInsensitive(): void
    {
        $result = $this->parser()->parse('CHARITY');

        self::assertSame([3], $result['cardIds']);
    }

    public function testASetCodeAndCardNumberSuffixIsIgnored(): void
    {
        $result = $this->parser()->parse('2 Doubt (MSW) 36');

        self::assertSame([36, 36], $result['cardIds']);
    }

    public function testASetCodeAloneWithNoCardNumberIsIgnored(): void
    {
        $result = $this->parser()->parse('Doubt (MSW)');

        self::assertSame([36], $result['cardIds']);
    }

    public function testSideboardCardsAreCapturedSeparatelyFromTheMainDeck(): void
    {
        $text = "Charity\nKindness\n\nSideboard\n1 Panic\n1 Euphoria";

        $result = $this->parser()->parse($text);

        self::assertSame([3, 17], $result['cardIds']);
        self::assertSame([48, 117], $result['sideboardCardIds']);
    }

    public function testASideboardHeaderIsOptional(): void
    {
        $text = "Charity\n\n1 Panic";

        $result = $this->parser()->parse($text);

        self::assertSame([3], $result['cardIds']);
        self::assertSame([48], $result['sideboardCardIds']);
    }

    public function testNoSideboardLeavesSideboardCardIdsEmpty(): void
    {
        $result = $this->parser()->parse('Charity');

        self::assertSame([], $result['sideboardCardIds']);
    }

    public function testSideboardSupportsCountsAndSetCodesLikeTheMainDeck(): void
    {
        $text = "Charity\n\nSideboard\n2 Doubt (MSW) 36";

        $result = $this->parser()->parse($text);

        self::assertSame([36, 36], $result['sideboardCardIds']);
    }

    public function testAnUnrecognizedCardInTheSideboardThrows(): void
    {
        $this->expectException(GameStateException::class);
        $this->expectExceptionMessage('sideboard line');

        $this->parser()->parse("Charity\n\nSideboard\nNot A Real Card");
    }

    public function testMetadataFieldsOtherThanNameAreIgnoredRatherThanRejected(): void
    {
        $text = "About\nName My Deck\nAuthor Somebody\n\nCharity";

        $result = $this->parser()->parse($text);

        self::assertSame('My Deck', $result['name']);
        self::assertSame([3], $result['cardIds']);
    }

    public function testAnUnrecognizedCardNameThrows(): void
    {
        $this->expectException(GameStateException::class);
        $this->expectExceptionMessage('Unrecognized card "Not A Real Card"');

        $this->parser()->parse('Not A Real Card');
    }

    public function testAnEmptyDecklistThrows(): void
    {
        $this->expectException(GameStateException::class);
        $this->expectExceptionMessage('no cards');

        $this->parser()->parse('');
    }

    public function testAnAboutBlockWithNoCardsAfterItThrows(): void
    {
        $this->expectException(GameStateException::class);

        $this->parser()->parse("About\nName My Deck");
    }
}
