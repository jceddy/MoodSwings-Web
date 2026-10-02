<?php

declare(strict_types=1);

namespace MoodSwings\Tests\Discord;

use MoodSwings\Discord\GridImageRenderer;
use PHPUnit\Framework\TestCase;

final class GridImageRendererTest extends TestCase
{
    public function testRendersAPngForA3x3GridWithTakenAndArtlessCells(): void
    {
        $cells = [];
        for ($i = 0; $i < 9; $i++) {
            $cells[] = $i % 2 === 0 ? null : ['path' => null, 'name' => "Card {$i}"];
        }

        $png = (new GridImageRenderer())->render($cells, 3);

        self::assertNotNull($png);
        self::assertStringStartsWith("\x89PNG", $png);
        $image = imagecreatefromstring($png);
        self::assertNotFalse($image);
        self::assertGreaterThan(imagesx($image), imagesy($image), 'three tall rows plus the bottom arrow gutter make a portrait image');
    }

    public function testRendersA4x4GridWiderThanA3x3(): void
    {
        $renderer = new GridImageRenderer();
        $cell = ['path' => null, 'name' => 'x'];

        $three = imagecreatefromstring((string) $renderer->render(array_fill(0, 9, $cell), 3));
        $four = imagecreatefromstring((string) $renderer->render(array_fill(0, 16, $cell), 4));

        self::assertGreaterThan(imagesx($three), imagesx($four));
    }

    public function testRefusesAMismatchedCellCountOrUnsupportedSize(): void
    {
        $renderer = new GridImageRenderer();

        self::assertNull($renderer->render(array_fill(0, 8, null), 3));
        self::assertNull($renderer->render([null], 1));
        self::assertNull($renderer->render(array_fill(0, 25, null), 5));
    }
}
