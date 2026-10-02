<?php

declare(strict_types=1);

namespace MoodSwings\Discord;

/**
 * Grid Draft's picture for Discord (reported live: "an image during the
 * draft with a visual layout of the grid, with numbered arrows along the
 * left side and bottom, pointing at their respective row/column -- the
 * user selects the desired row/column by number"). Pure GD compositing,
 * like BoardImageRenderer: no game-state knowledge, no HTTP, no
 * filesystem probing -- the caller hands in each cell's already-resolved
 * art file and this lays them out as an N x N grid with a right-pointing
 * arrow per row down the left edge and an up-pointing arrow per column
 * along the bottom, each carrying its 1-based number. A cell already
 * taken this round (null) is drawn as a dark, crossed-out slot, and an
 * arrow whose whole line is already taken is dimmed -- nothing to pick
 * there.
 *
 * The built-in bitmap fonts only (see BoardImageRenderer's own docblock
 * for why not TTF); the arrow numbers are drawn small and scaled up so
 * they stay readable.
 */
final class GridImageRenderer
{
    private const CELL_WIDTH = 150;
    private const CELL_HEIGHT = 210;
    private const GAP = 8;
    private const PADDING = 10;
    /** Width of the left arrow gutter / height of the bottom arrow gutter. */
    private const GUTTER = 64;
    private const FONT = 5;
    private const NUMBER_SCALE = 3;

    /**
     * @param array<int, array{path: ?string, name: string}|null> $cells row-major, size^2 entries; null = taken
     * @return string|null raw PNG bytes, or null for a cell count that isn't a perfect square of 2-4
     */
    public function render(array $cells, int $size): ?string
    {
        if ($size < 2 || $size > 4 || count($cells) !== $size * $size) {
            return null;
        }

        $width = self::PADDING + self::GUTTER + $size * (self::CELL_WIDTH + self::GAP) + self::PADDING - self::GAP;
        $height = self::PADDING + $size * (self::CELL_HEIGHT + self::GAP) - self::GAP + self::GAP + self::GUTTER + self::PADDING;

        $canvas = imagecreatetruecolor($width, $height);
        imagefill($canvas, 0, 0, imagecolorallocate($canvas, 54, 57, 63));

        $arrowLive = imagecolorallocate($canvas, 88, 101, 242);
        $arrowDim = imagecolorallocate($canvas, 79, 84, 92);
        $slot = imagecolorallocate($canvas, 40, 43, 48);
        $slotLine = imagecolorallocate($canvas, 70, 74, 82);
        $label = imagecolorallocate($canvas, 220, 221, 222);

        $originX = self::PADDING + self::GUTTER;
        $originY = self::PADDING;

        for ($row = 0; $row < $size; $row++) {
            for ($column = 0; $column < $size; $column++) {
                $x = $originX + $column * (self::CELL_WIDTH + self::GAP);
                $y = $originY + $row * (self::CELL_HEIGHT + self::GAP);
                $cell = $cells[$row * $size + $column];

                if ($cell === null) {
                    imagefilledrectangle($canvas, $x, $y, $x + self::CELL_WIDTH - 1, $y + self::CELL_HEIGHT - 1, $slot);
                    imagerectangle($canvas, $x, $y, $x + self::CELL_WIDTH - 1, $y + self::CELL_HEIGHT - 1, $slotLine);
                    imageline($canvas, $x + 20, $y + 20, $x + self::CELL_WIDTH - 21, $y + self::CELL_HEIGHT - 21, $slotLine);
                    imageline($canvas, $x + self::CELL_WIDTH - 21, $y + 20, $x + 20, $y + self::CELL_HEIGHT - 21, $slotLine);
                    imagestring($canvas, self::FONT, $x + (int) ((self::CELL_WIDTH - imagefontwidth(self::FONT) * 5) / 2), $y + (int) (self::CELL_HEIGHT / 2) - 8, 'taken', $slotLine);
                    continue;
                }

                $this->drawCard($canvas, $cell, $x, $y, $label);
            }
        }

        for ($row = 0; $row < $size; $row++) {
            $live = false;
            for ($column = 0; $column < $size; $column++) {
                $live = $live || $cells[$row * $size + $column] !== null;
            }
            $centerY = $originY + $row * (self::CELL_HEIGHT + self::GAP) + (int) (self::CELL_HEIGHT / 2);
            $this->drawRowArrow($canvas, self::PADDING, $centerY, $row + 1, $live ? $arrowLive : $arrowDim);
        }

        $bottomY = $originY + $size * (self::CELL_HEIGHT + self::GAP);
        for ($column = 0; $column < $size; $column++) {
            $live = false;
            for ($row = 0; $row < $size; $row++) {
                $live = $live || $cells[$row * $size + $column] !== null;
            }
            $centerX = $originX + $column * (self::CELL_WIDTH + self::GAP) + (int) (self::CELL_WIDTH / 2);
            $this->drawColumnArrow($canvas, $centerX, $bottomY, $column + 1, $live ? $arrowLive : $arrowDim);
        }

        ob_start();
        imagepng($canvas);
        $bytes = ob_get_clean();
        imagedestroy($canvas);

        return $bytes === false ? null : $bytes;
    }

    /** @param array{path: ?string, name: string} $cell */
    private function drawCard(\GdImage $canvas, array $cell, int $x, int $y, int $labelColor): void
    {
        $contents = $cell['path'] !== null ? @file_get_contents($cell['path']) : false;
        $source = $contents === false ? false : @imagecreatefromstring($contents);
        if ($source !== false) {
            imagecopyresampled($canvas, $source, $x, $y, 0, 0, self::CELL_WIDTH, self::CELL_HEIGHT, imagesx($source), imagesy($source));
            imagedestroy($source);

            return;
        }

        // No art on this deployment: a plain slot carrying the card's name.
        imagefilledrectangle($canvas, $x, $y, $x + self::CELL_WIDTH - 1, $y + self::CELL_HEIGHT - 1, imagecolorallocate($canvas, 66, 70, 77));
        $maxChars = (int) ((self::CELL_WIDTH - 8) / imagefontwidth(self::FONT));
        imagestring($canvas, self::FONT, $x + 4, $y + (int) (self::CELL_HEIGHT / 2) - 8, substr($cell['name'], 0, $maxChars), $labelColor);
    }

    /** A right-pointing arrow in the left gutter, centered on $centerY, numbered $number. */
    private function drawRowArrow(\GdImage $canvas, int $left, int $centerY, int $number, int $color): void
    {
        $bodyRight = $left + self::GUTTER - 22;
        $half = 20;
        imagefilledrectangle($canvas, $left, $centerY - $half, $bodyRight, $centerY + $half, $color);
        imagefilledpolygon($canvas, [
            $bodyRight, $centerY - $half - 8,
            $left + self::GUTTER - 2, $centerY,
            $bodyRight, $centerY + $half + 8,
        ], $color);
        $this->drawNumber($canvas, (string) $number, $left + (int) (($bodyRight - $left) / 2), $centerY);
    }

    /** An up-pointing arrow below the grid at column center $centerX, numbered $number. */
    private function drawColumnArrow(\GdImage $canvas, int $centerX, int $top, int $number, int $color): void
    {
        $half = 20;
        $headBottom = $top + 24;
        imagefilledpolygon($canvas, [
            $centerX - $half - 8, $headBottom,
            $centerX, $top + 2,
            $centerX + $half + 8, $headBottom,
        ], $color);
        imagefilledrectangle($canvas, $centerX - $half, $headBottom, $centerX + $half, $top + self::GUTTER - 4, $color);
        $this->drawNumber($canvas, (string) $number, $centerX, $headBottom + (int) ((self::GUTTER - 28) / 2));
    }

    /** Draws $text centered on ($centerX, $centerY), scaled up from the built-in font. */
    private function drawNumber(\GdImage $canvas, string $text, int $centerX, int $centerY): void
    {
        $w = imagefontwidth(self::FONT) * strlen($text);
        $h = imagefontheight(self::FONT);
        $small = imagecreatetruecolor($w, $h);
        imagealphablending($small, false);
        imagesavealpha($small, true);
        imagefill($small, 0, 0, imagecolorallocatealpha($small, 0, 0, 0, 127));
        imagestring($small, self::FONT, 0, 0, $text, imagecolorallocate($small, 255, 255, 255));

        $scaledW = $w * self::NUMBER_SCALE;
        $scaledH = $h * self::NUMBER_SCALE;
        imagealphablending($canvas, true);
        imagecopyresized($canvas, $small, $centerX - (int) ($scaledW / 2), $centerY - (int) ($scaledH / 2), 0, 0, $scaledW, $scaledH, $w, $h);
        imagedestroy($small);
    }
}
