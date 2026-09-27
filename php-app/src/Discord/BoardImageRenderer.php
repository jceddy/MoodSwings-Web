<?php

declare(strict_types=1);

namespace MoodSwings\Discord;

/**
 * Issue #233 follow-up (reported live: "would it be possible to use some
 * kind of image library to render, say, the cards in play as a single
 * image to embed in the game display message?", then "would it be
 * possible to arrange the cards similarly to how we do in the web
 * client, including the player's names, badges on the cards to indicate
 * current value, etc."). Pure GD compositing -- no game-state/rules
 * knowledge, no HTTP, no filesystem probing (that's
 * DiscordGameCommandService::cardArtFilePath()'s own job) -- just "given
 * these already-resolved, already-existing card art files grouped by
 * player, lay them out the way the web board does and tile them into one
 * image." GD (not the Imagick PECL extension, which the user's shared
 * hosting can't install) is confirmed bundled with PHP on the target
 * deployment, including webp DECODE support -- see
 * imagecreatefromstring()'s own docblock below for why the OUTPUT format
 * is PNG instead.
 *
 * Deliberately NOT the web board's own viewer-relative north/south/east/
 * west seating (web-static/js/game.js's own inPlayZoneAssignments()) --
 * this image is fetched once by Discord's own servers and cached at a
 * single URL keyed only by game id (see
 * DiscordGameCommandService::boardImageUrl()), with no per-viewer
 * variant, so "south is always the viewer's own seat" can't apply here:
 * whichever player happens to invoke /moodswings first would otherwise
 * freeze everyone else's own view of the image into THEIR perspective.
 * Instead, one labeled row per player with at least one mood in play,
 * in the same $state['players'] seat order boardMessage()'s own
 * inPlaySummary() already lists them in -- stable and identical for
 * every viewer, exactly like every other public board-image URL.
 *
 * Text rendering deliberately uses GD's own built-in bitmap fonts
 * (imagestring()/imagefilledellipse() below) rather than imagettftext() --
 * TTF rendering needs FreeType support in GD's own build AND a font file
 * on disk, and the user's own hosting research only confirmed GD itself,
 * not FreeType specifically. The built-in fonts work in EVERY GD build
 * unconditionally, so this can't repeat the same "looked fine locally,
 * silently didn't work in production" class of bug boardImageUrl()'s own
 * APP_URL/SiteUrl::root() mixup just did (see php-app/README.md), just
 * for a missing font instead of a wrong path.
 */
final class BoardImageRenderer
{
    /** Roughly matches the MSW card art's own ~744x1039 aspect ratio. */
    private const CELL_WIDTH = 150;
    private const CELL_HEIGHT = 210;
    private const PADDING = 10;

    /** GD's own largest built-in bitmap font (1-5) -- see this class's own docblock for why not imagettftext(). */
    private const FONT = 5;
    private const LABEL_HEIGHT = 20;

    /**
     * Defensive cap, the same spirit as every other list this class's
     * caller already truncates (MAX_SELECT_OPTIONS, discardPileSummary()'s
     * own strlen() cap, ...) -- a pathological game with dozens of moods
     * in play for a single player (Suspicion/Pride loops, chaos formats
     * once those get their own composite image someday) shouldn't be
     * able to make this render an unbounded canvas.
     */
    private const MAX_CARDS_PER_PLAYER = 12;

    /**
     * @param array<int, array{username: string, cards: array<int, array{path: string, value: int, base_value: int}>}> $players
     *     one entry per player who currently has at least one mood in
     *     play (a player with none is simply omitted, same as
     *     inPlaySummary()'s own text never bothering to print an empty
     *     row for a zero-card player); each card's own `path` is an
     *     already-resolved local file (a path that fails to decode is
     *     silently skipped -- an empty cell -- rather than aborting the
     *     whole image), and `value`/`base_value` drive the same current-
     *     value badge web-static/js/game.js's own buildCardThumb() shows
     *     (`if (card.value !== card.base_value)`).
     * @return string|null raw PNG bytes, or null when given no players
     *     with any cards at all -- the caller's signal that there's
     *     nothing to render.
     */
    public function render(array $players): ?string
    {
        $players = array_values(array_filter($players, static fn (array $player): bool => $player['cards'] !== []));
        if ($players === []) {
            return null;
        }

        $maxCardsInARow = 0;
        $maxLabelWidth = 0;
        foreach ($players as $player) {
            $maxCardsInARow = max($maxCardsInARow, min(count($player['cards']), self::MAX_CARDS_PER_PLAYER));
            $maxLabelWidth = max($maxLabelWidth, imagefontwidth(self::FONT) * strlen($player['username']));
        }

        $width = self::PADDING + max($maxCardsInARow * (self::CELL_WIDTH + self::PADDING), $maxLabelWidth + 2 * self::PADDING);
        $rowHeight = self::LABEL_HEIGHT + self::CELL_HEIGHT + self::PADDING;
        $height = self::PADDING + count($players) * $rowHeight;

        $canvas = imagecreatetruecolor($width, $height);
        // Discord's own dark embed background -- so a card image's own
        // (often transparent) margins, and the space around a shorter
        // row than the widest one, blend in rather than showing as stark
        // white/black rectangles.
        $background = imagecolorallocate($canvas, 54, 57, 63);
        imagefill($canvas, 0, 0, $background);
        // Discord's own light embed text color, for each row's username
        // label -- the same "one name per zone" reasoning
        // web-static/js/game.js's own renderInPlay() docblock gives for
        // replacing an owner caption on every individual card.
        $textColor = imagecolorallocate($canvas, 220, 221, 222);

        $y = self::PADDING;
        foreach ($players as $player) {
            imagestring($canvas, self::FONT, self::PADDING, $y, $player['username'], $textColor);
            $y += self::LABEL_HEIGHT;

            $x = self::PADDING;
            foreach (array_slice($player['cards'], 0, self::MAX_CARDS_PER_PLAYER) as $card) {
                $this->drawCard($canvas, $card, $x, $y);
                $x += self::CELL_WIDTH + self::PADDING;
            }

            $y += self::CELL_HEIGHT + self::PADDING;
        }

        ob_start();
        imagepng($canvas);
        $bytes = ob_get_clean();
        imagedestroy($canvas);

        return $bytes === false ? null : $bytes;
    }

    /** @param array{path: string, value: int, base_value: int} $card */
    private function drawCard(\GdImage $canvas, array $card, int $x, int $y): void
    {
        $contents = @file_get_contents($card['path']);
        $source = $contents === false ? false : @imagecreatefromstring($contents);
        if ($source !== false) {
            imagecopyresampled($canvas, $source, $x, $y, 0, 0, self::CELL_WIDTH, self::CELL_HEIGHT, imagesx($source), imagesy($source));
            imagedestroy($source);
        }

        // Matches buildCardThumb()'s own `.card-thumb__badge--value`
        // (top-right corner, dark pill, white text) -- shown only when
        // something (a dice roll, a permanent boost/reduction, a
        // Creativity copy's own current print, ...) has actually moved
        // this card away from its base printed value, the same
        // condition the web client itself checks.
        if ($card['value'] !== $card['base_value']) {
            $this->drawValueBadge($canvas, $card['value'], $x, $y);
        }
    }

    private function drawValueBadge(\GdImage $canvas, int $value, int $cardX, int $cardY): void
    {
        $text = (string) $value;
        $diameter = max(22, imagefontwidth(self::FONT) * strlen($text) + 10);
        $centerX = $cardX + self::CELL_WIDTH - (int) ($diameter / 2) - 4;
        $centerY = $cardY + (int) ($diameter / 2) + 4;

        // rgba(0, 0, 0, 0.75) in buildCardThumb()'s own CSS -- GD's own
        // alpha channel runs 0 (opaque) to 127 (fully transparent), so
        // 75% opaque is 0.25 * 127.
        $badgeBackground = imagecolorallocatealpha($canvas, 0, 0, 0, (int) (0.25 * 127));
        imagefilledellipse($canvas, $centerX, $centerY, $diameter, $diameter, $badgeBackground);

        $white = imagecolorallocate($canvas, 255, 255, 255);
        $textX = $centerX - (int) (imagefontwidth(self::FONT) * strlen($text) / 2);
        $textY = $centerY - (int) (imagefontheight(self::FONT) / 2);
        imagestring($canvas, self::FONT, $textX, $textY, $text, $white);
    }
}
