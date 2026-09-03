<?php

declare(strict_types=1);

namespace MoodSwings\Tests\Bot;

use MoodSwings\Bot\Determinizer;
use MoodSwings\Rules\BoardState;
use MoodSwings\Rules\DefaultEffectRegistry;
use MoodSwings\Tests\Rules\CatalogFixture;
use PHPUnit\Framework\TestCase;

final class DeterminizerTest extends TestCase
{
    use CatalogFixture;

    private Determinizer $determinizer;

    protected function setUp(): void
    {
        $this->determinizer = new Determinizer();
    }

    public function testDeterminizeKeepsTheViewersOwnHandExactlyAsIs(): void
    {
        $state = new BoardState($this->sampleCatalog(), DefaultEffectRegistry::build(), [1, 2], [1 => [55, 32], 2 => [8, 9]], [100, 101, 102]);

        $result = $this->determinizer->determinize($state, 1);

        self::assertSame([55, 32], $result->hand(1), 'the viewer\'s own hand is the one thing they genuinely know -- never reshuffled');
    }

    public function testDeterminizePreservesEveryHandAndDeckSizeWhileReshufflingContents(): void
    {
        $state = new BoardState($this->sampleCatalog(), DefaultEffectRegistry::build(), [1, 2], [1 => [55, 32], 2 => [8, 9, 5]], [100, 101, 102, 103]);

        $result = $this->determinizer->determinize($state, 1);

        self::assertCount(2, $result->hand(1));
        self::assertCount(3, $result->hand(2), 'opponent hand SIZE is preserved even though its contents are resampled');
        self::assertCount(4, $result->deck(), 'the shared deck\'s own size is preserved too');

        // Every hidden card (opponent hand + deck) still comes from the
        // same pool that was there before -- nothing is invented or lost.
        $hiddenBefore = [8, 9, 5, 100, 101, 102, 103];
        $hiddenAfter = [...$result->hand(2), ...$result->deck()];
        sort($hiddenBefore);
        sort($hiddenAfter);
        self::assertSame($hiddenBefore, $hiddenAfter);
    }

    public function testDeterminizeNeverMutatesTheOriginalBoardState(): void
    {
        $state = new BoardState($this->sampleCatalog(), DefaultEffectRegistry::build(), [1, 2], [1 => [55, 32], 2 => [8, 9]], [100, 101]);

        $this->determinizer->determinize($state, 1);

        self::assertSame([8, 9], $state->hand(2), 'the real, live BoardState must come back completely untouched');
        self::assertSame([100, 101], $state->deck());
    }

    public function testDeterminizeReshufflesTheViewersOwnDeckToo(): void
    {
        // Nobody knows their own future draws either -- see this class's
        // own docblock. A duel-style separate-decks game exercises this:
        // even the viewer's own deck size is preserved, but it's part of
        // the hidden pool, not exempted the way their hand is.
        $state = new BoardState(
            $this->sampleCatalog(),
            DefaultEffectRegistry::build(),
            [1, 2],
            [1 => [55], 2 => [32]],
            [1 => [100, 101], 2 => [102, 103, 104]],
            hasSeparateDecks: true,
        );

        $result = $this->determinizer->determinize($state, 1);

        self::assertCount(2, $result->deck(1));
        self::assertCount(3, $result->deck(2));
    }
}
