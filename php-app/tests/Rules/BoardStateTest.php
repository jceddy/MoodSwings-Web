<?php

declare(strict_types=1);

namespace MoodSwings\Tests\Rules;

use InvalidArgumentException;
use MoodSwings\Rules\BoardState;
use MoodSwings\Rules\DefaultEffectRegistry;
use PHPUnit\Framework\TestCase;

final class BoardStateTest extends TestCase
{
    use CatalogFixture;

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

    public function testMoveHandToInPlayAndBack(): void
    {
        $state = $this->boardState(hands: [1 => [3]]);

        self::assertTrue($state->isInHand(1, 3));
        self::assertFalse($state->isInPlay(3));

        $state->moveHandToInPlay(1, 3);

        self::assertFalse($state->isInHand(1, 3));
        self::assertTrue($state->isInPlay(3));
        self::assertSame(1, $state->ownerOf(3));

        $state->moveInPlayToHand(3);

        self::assertTrue($state->isInHand(1, 3));
        self::assertFalse($state->isInPlay(3));
    }

    public function testMoveInPlayToDiscard(): void
    {
        $state = $this->boardState(hands: [1 => [3]]);
        $state->moveHandToInPlay(1, 3);

        $state->moveInPlayToDiscard(3);

        self::assertFalse($state->isInPlay(3));
        self::assertSame([3], $state->discardPile());
    }

    public function testMoveInPlayToBottomOfDeck(): void
    {
        $state = $this->boardState(hands: [1 => [3]], deck: [99]);
        $state->moveHandToInPlay(1, 3);

        $state->moveInPlayToBottomOfDeck(3);

        self::assertFalse($state->isInPlay(3));
        self::assertSame([99, 3], $state->deck());
    }

    public function testMoveHandToDiscardAndBottomOfDeck(): void
    {
        $state = $this->boardState(hands: [1 => [3, 7]], deck: [99]);

        $state->moveHandToDiscard(1, 3);
        self::assertSame([3], $state->discardPile());
        self::assertFalse($state->isInHand(1, 3));

        $state->moveHandToBottomOfDeck(1, 7);
        self::assertSame([99, 7], $state->deck());
        self::assertFalse($state->isInHand(1, 7));
    }

    public function testMoveDiscardToHand(): void
    {
        $state = $this->boardState(discard: [3]);

        $state->moveDiscardToHand(2, 3);

        self::assertTrue($state->isInHand(2, 3));
        self::assertSame([], $state->discardPile());
    }

    public function testGiveInPlayToPlayer(): void
    {
        $state = $this->boardState(hands: [1 => [3]]);
        $state->moveHandToInPlay(1, 3);

        $state->giveInPlayToPlayer(3, 2);

        self::assertSame(2, $state->ownerOf(3));
    }

    public function testDrawCardMovesTopOfDeckToHand(): void
    {
        $state = $this->boardState(deck: [10, 20, 30]);

        $drawn = $state->drawCard(1);

        self::assertSame(10, $drawn);
        self::assertSame([20, 30], $state->deck());
        self::assertTrue($state->isInHand(1, 10));
    }

    public function testDrawCardFromEmptyDeckReturnsNull(): void
    {
        $state = $this->boardState();

        self::assertNull($state->drawCard(1));
    }

    public function testMovingUnknownCardOutOfHandThrows(): void
    {
        $state = $this->boardState(hands: [1 => []]);

        $this->expectException(InvalidArgumentException::class);
        $state->moveHandToInPlay(1, 999);
    }

    public function testSuppressionZeroesValueRegardlessOfUnderlyingValue(): void
    {
        $state = $this->boardState(hands: [1 => [56]]); // Betrayal, flat base value 6
        $state->moveHandToInPlay(1, 56);

        self::assertSame(6, $state->valueOf(56));

        $state->suppress(56, 'end_of_round');
        self::assertTrue($state->isSuppressed(56));
        self::assertSame(0, $state->valueOf(56));

        $state->clearEndOfRoundSuppressions();
        self::assertFalse($state->isSuppressed(56));
        self::assertSame(6, $state->valueOf(56));
    }

    public function testClearSuppressionsFromSource(): void
    {
        $state = $this->boardState(hands: [1 => [56]]);
        $state->moveHandToInPlay(1, 56);
        $state->suppress(56, 'while_source_in_play', sourceCardId: 42);

        $state->clearSuppressionsFrom(42);

        self::assertFalse($state->isSuppressed(56));
    }

    public function testColorOfWithoutImaginationReturnsCatalogColor(): void
    {
        $state = $this->boardState(hands: [1 => [56]]);
        $state->moveHandToInPlay(1, 56);

        self::assertSame('black', $state->colorOf(56));
    }

    public function testColorOfWithImaginationInPlayReturnsChosenColor(): void
    {
        $state = $this->boardState(hands: [1 => [42, 56]]);
        $state->moveHandToInPlay(1, 42); // Imagination
        $state->setEffectState(42, 'color', 'green');
        $state->moveHandToInPlay(1, 56); // Betrayal, printed black

        self::assertSame('green', $state->colorOf(56));
        self::assertSame('green', $state->colorOf(42));
    }

    public function testValueOverrideTakesPrecedenceOverBaseValue(): void
    {
        $state = $this->boardState(hands: [1 => [8]]);
        $state->moveHandToInPlay(1, 8); // Dignity, base 3

        self::assertSame(3, $state->valueOf(8));

        $state->setValueOverride(8, 5);

        self::assertSame(5, $state->valueOf(8));
    }

    public function testEffectiveCardIdFollowsCopiedCard(): void
    {
        $state = $this->boardState(hands: [1 => [32]]);
        $state->moveHandToInPlay(1, 32, copiedCardId: 3); // Creativity copying Charity

        self::assertSame(3, $state->effectiveCardId(32));
        self::assertSame(1, $state->valueOf(32)); // Charity's base value
    }

    public function testTurnAndPlaysRemaining(): void
    {
        $state = $this->boardState();

        $state->startTurn(1);
        self::assertSame(1, $state->currentPlayerId());
        self::assertSame(1, $state->playsRemaining());

        $state->grantExtraPlay();
        self::assertSame(2, $state->playsRemaining());

        $state->consumePlay();
        self::assertSame(1, $state->playsRemaining());

        $state->consumePlay();
        $state->consumePlay(); // should not go negative
        self::assertSame(0, $state->playsRemaining());
    }

    public function testStartTurnWithHurtFeelingsGrantsTwoPlays(): void
    {
        $state = $this->boardState();

        $state->startTurn(1, hasHurtFeelings: true);

        self::assertSame(2, $state->playsRemaining());
    }

    public function testCatalogRowThrowsForUnknownCard(): void
    {
        $state = $this->boardState();

        $this->expectException(InvalidArgumentException::class);
        $state->catalogRow(999999);
    }
}
