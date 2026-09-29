<?php

declare(strict_types=1);

namespace MoodSwings\Tests\Discord;

use MoodSwings\Discord\BoardImageRenderer;
use PHPUnit\Framework\TestCase;

/**
 * Pure GD compositing, no game-state/DB dependency at all -- unlike
 * every other Discord test (GameServiceIntegrationTest.php's own
 * "playing the game via Discord" section), this needs no MySQL
 * connection, so it lives in its own fast unit test instead.
 */
final class BoardImageRendererTest extends TestCase
{
    private const CARD_ART_DIR = __DIR__ . '/../../../web-static/img/cards/MSW/';

    public function testReturnsNullWhenGivenNoPlayersAtAll(): void
    {
        self::assertNull((new BoardImageRenderer())->render([]));
    }

    public function testReturnsNullWhenEveryPlayerHasNoCards(): void
    {
        $image = (new BoardImageRenderer())->render([
            ['username' => 'nobody-has-anything', 'cards' => []],
        ]);

        self::assertNull($image);
    }

    /**
     * Reported live: "would it be possible to arrange the cards
     * similarly to how we do in the web client, including the player's
     * names, badges on the cards to indicate current value, etc." --
     * this checks the actual layout consequence of "one row per player"
     * (see this class's own docblock for why NOT the web board's own
     * viewer-relative seating): a second player's own row makes the
     * image taller than a single-row image of the exact same cards,
     * proving the rows are genuinely stacked rather than folded into one
     * shared grid the way the earlier "just tile every in-play card"
     * version of this class used to.
     */
    public function testEachPlayerGetsItsOwnRowTallerThanASingleRow(): void
    {
        $renderer = new BoardImageRenderer();
        $card = ['path' => self::CARD_ART_DIR . '66-hate.webp', 'value' => 0, 'base_value' => 0];

        $oneRow = $renderer->render([
            ['username' => 'solo-player', 'cards' => [$card]],
        ]);
        $twoRows = $renderer->render([
            ['username' => 'player-one', 'cards' => [$card]],
            ['username' => 'player-two', 'cards' => [$card]],
        ]);

        self::assertNotNull($oneRow);
        self::assertNotNull($twoRows);
        self::assertGreaterThan(imagesy(imagecreatefromstring($oneRow)), imagesy(imagecreatefromstring($twoRows)));
    }

    /**
     * A player with nothing in play gets no row at all -- same as
     * inPlaySummary()'s own text listing never printing an empty line
     * for a zero-card player (see this class's own docblock).
     */
    public function testOmitsAPlayerRowEntirelyWhenThatPlayerHasNoCards(): void
    {
        $renderer = new BoardImageRenderer();
        $card = ['path' => self::CARD_ART_DIR . '66-hate.webp', 'value' => 0, 'base_value' => 0];

        $withEmptyPlayer = $renderer->render([
            ['username' => 'has-a-card', 'cards' => [$card]],
            ['username' => 'has-nothing', 'cards' => []],
        ]);
        $withoutEmptyPlayer = $renderer->render([
            ['username' => 'has-a-card', 'cards' => [$card]],
        ]);

        self::assertNotNull($withEmptyPlayer);
        self::assertNotNull($withoutEmptyPlayer);
        self::assertSame(
            imagesy(imagecreatefromstring($withoutEmptyPlayer)),
            imagesy(imagecreatefromstring($withEmptyPlayer)),
        );
    }

    /**
     * Reported live alongside the layout ask above: "badges on the cards
     * to indicate current value" -- matches buildCardThumb()'s own exact
     * condition (`card.value !== card.base_value`). Checked by sampling
     * the top-right corner pixel a badge would darken (the dark,
     * semi-transparent pill background) rather than asserting exact
     * rendering -- see this class's own docblock/README for why
     * image-rendering tests stay at this level, not pixel-perfect.
     */
    public function testDrawsAValueBadgeOnlyWhenCurrentValueDiffersFromBase(): void
    {
        $renderer = new BoardImageRenderer();
        $art = self::CARD_ART_DIR . '5-complacency.webp';

        $unchanged = $renderer->render([
            ['username' => 'p', 'cards' => [['path' => $art, 'value' => 4, 'base_value' => 4]]],
        ]);
        $boosted = $renderer->render([
            ['username' => 'p', 'cards' => [['path' => $art, 'value' => 9, 'base_value' => 4]]],
        ]);

        self::assertNotNull($unchanged);
        self::assertNotNull($boosted);

        // The badge's own center pixel, computed the exact same way
        // drawValueBadge() itself does: the one card cell sits at
        // (PADDING, PADDING + LABEL_HEIGHT), and a single-digit value's
        // own badge diameter floors at 22 (imagefontwidth(5) * 1 + 10 =
        // 19, under that floor).
        $unchangedImage = imagecreatefromstring($unchanged);
        $boostedImage = imagecreatefromstring($boosted);
        $cardX = 10; // PADDING
        $cardY = 30; // PADDING + LABEL_HEIGHT
        $diameter = 22;
        $x = $cardX + 150 - (int) ($diameter / 2) - 4; // CELL_WIDTH - floor(diameter/2) - 4
        $y = $cardY + (int) ($diameter / 2) + 4;

        $unchangedColor = imagecolorat($unchangedImage, $x, $y);
        $boostedColor = imagecolorat($boostedImage, $x, $y);

        self::assertNotSame($unchangedColor, $boostedColor);
    }
}
