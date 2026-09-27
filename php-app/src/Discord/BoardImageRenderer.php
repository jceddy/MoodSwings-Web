<?php

declare(strict_types=1);

namespace MoodSwings\Discord;

/**
 * Issue #233 follow-up (reported live: "would it be possible to use some
 * kind of image library to render, say, the cards in play as a single
 * image to embed in the game display message?"). Pure GD compositing --
 * no game-state/rules knowledge, no HTTP, no filesystem probing (that's
 * DiscordGameCommandService::cardArtFilePath()'s own job) -- just "given
 * these already-resolved, already-existing card art files, tile them
 * into one image." GD (not the Imagick PECL extension, which the user's
 * shared hosting can't install) is confirmed bundled with PHP on the
 * target deployment, including webp DECODE support -- see
 * imagecreatefromstring()'s own docblock below for why the OUTPUT format
 * is PNG instead.
 */
final class BoardImageRenderer
{
    /** Roughly matches the MSW card art's own ~744x1039 aspect ratio. */
    private const CELL_WIDTH = 150;
    private const CELL_HEIGHT = 210;
    private const PADDING = 10;

    /**
     * Defensive cap, the same spirit as every other list this class's
     * caller already truncates (MAX_SELECT_OPTIONS, discardPileSummary()'s
     * own strlen() cap, ...) -- a pathological game with dozens of moods
     * in play (Suspicion/Pride loops, chaos formats once those get their
     * own composite image someday) shouldn't be able to make this render
     * an unbounded canvas.
     */
    private const MAX_CARDS = 40;

    /**
     * @param string[] $cardArtFilePaths one local file path per in-play
     *     card, in display order; a path that fails to decode is silently
     *     skipped (an empty cell) rather than aborting the whole image.
     * @return string|null raw PNG bytes, or null when given no paths at
     *     all -- the caller's signal that there's nothing to render.
     */
    public function render(array $cardArtFilePaths): ?string
    {
        $cardArtFilePaths = array_slice($cardArtFilePaths, 0, self::MAX_CARDS);
        if ($cardArtFilePaths === []) {
            return null;
        }

        $columns = (int) ceil(sqrt(count($cardArtFilePaths)));
        $rows = (int) ceil(count($cardArtFilePaths) / $columns);

        $canvas = imagecreatetruecolor(
            $columns * self::CELL_WIDTH + ($columns + 1) * self::PADDING,
            $rows * self::CELL_HEIGHT + ($rows + 1) * self::PADDING,
        );
        // Discord's own dark embed background -- so a card image's own
        // (often transparent) margins blend in rather than showing as
        // stark white/black rectangles.
        $background = imagecolorallocate($canvas, 54, 57, 63);
        imagefill($canvas, 0, 0, $background);

        foreach (array_values($cardArtFilePaths) as $index => $path) {
            $contents = @file_get_contents($path);
            $source = $contents === false ? false : @imagecreatefromstring($contents);
            if ($source === false) {
                continue;
            }

            $column = $index % $columns;
            $row = intdiv($index, $columns);
            $x = self::PADDING + $column * (self::CELL_WIDTH + self::PADDING);
            $y = self::PADDING + $row * (self::CELL_HEIGHT + self::PADDING);

            imagecopyresampled($canvas, $source, $x, $y, 0, 0, self::CELL_WIDTH, self::CELL_HEIGHT, imagesx($source), imagesy($source));
            imagedestroy($source);
        }

        ob_start();
        imagepng($canvas);
        $bytes = ob_get_clean();
        imagedestroy($canvas);

        return $bytes === false ? null : $bytes;
    }
}
