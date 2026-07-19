<?php

declare(strict_types=1);

namespace MoodSwings\Game;

use MoodSwings\Game\Exceptions\GameStateException;

/**
 * Parses a plain-text decklist (uploaded as a file or pasted into a form
 * field -- see GameService::createGame()'s 'custom' deck_type) into a
 * resolved card list plus optional deck name. Pure and DB-free: the
 * caller supplies the catalog's own name => id map (case-insensitive
 * lookup key), so this class has no persistence concerns of its own and
 * is fully unit-testable without a database.
 *
 * Format (see this class's own tests for worked examples):
 *
 *   About                     <- optional metadata block, only if this
 *   Name My Awesome Deck         is the file's very first line
 *                             <- blank line ends the metadata block
 *   1 Charity
 *   2 Chivalry (MSW) 4        <- optional " (SET)" and/or " NUMBER" after
 *   Complacency                  the name, both ignored (only one Set
 *                                 exists today); a bare line with no
 *                                 leading count means one copy
 *                             <- blank line ends the main deck
 *   Sideboard                 <- optional header line, itself not a card
 *   1 Discipline               <- sideboard lines, captured separately
 *                                 into sideboardCardIds (issue #92)
 */
final class DecklistParser
{
    /** @param array<string, int> $catalogIdsByName lowercased card name => catalog card id */
    public function __construct(private readonly array $catalogIdsByName)
    {
    }

    /**
     * @return array{name: ?string, cardIds: int[], sideboardCardIds: int[]}
     */
    public function parse(string $text): array
    {
        $lines = preg_split('/\r\n|\r|\n/', $text) ?: [];
        $index = 0;
        $count = count($lines);

        $name = null;
        if ($index < $count && trim($lines[$index]) === 'About') {
            $index++;
            while ($index < $count && trim($lines[$index]) !== '') {
                [$field, $value] = array_pad(preg_split('/\s+/', trim($lines[$index]), 2) ?: [], 2, '');
                if ($field === 'Name' && $value !== '') {
                    $name = mb_substr($value, 0, 120);
                }
                $index++;
            }
            if ($index < $count && trim($lines[$index]) === '') {
                $index++;
            }
        }

        $mainLines = [];
        while ($index < $count && trim($lines[$index]) !== '') {
            $mainLines[] = $lines[$index];
            $index++;
        }

        $cardIds = $this->parseCardLines($mainLines, 'decklist');
        if ($cardIds === []) {
            throw new GameStateException('The decklist has no cards in it.');
        }

        // Everything after the main deck's own trailing blank line: an
        // optional literal 'Sideboard' header (itself not a card), then
        // more card lines in the exact same format as the main deck.
        if ($index < $count && trim($lines[$index]) === '') {
            $index++;
        }
        if ($index < $count && strcasecmp(trim($lines[$index]), 'Sideboard') === 0) {
            $index++;
        }
        $sideboardCardIds = $this->parseCardLines(array_slice($lines, $index), 'sideboard');

        return ['name' => $name, 'cardIds' => $cardIds, 'sideboardCardIds' => $sideboardCardIds];
    }

    /**
     * @param string[] $lines
     * @return int[]
     */
    private function parseCardLines(array $lines, string $sectionLabel): array
    {
        $cardIds = [];
        foreach ($lines as $lineNumber => $line) {
            $trimmed = trim($line);
            if ($trimmed === '' || strcasecmp($trimmed, 'Sideboard') === 0) {
                continue;
            }

            if (!preg_match('/^(?:(\d+)\s+)?(.+?)(?:\s+\(([^)]+)\))?(?:\s+(\d+))?$/', $trimmed, $matches)) {
                throw new GameStateException("Couldn't understand {$sectionLabel} line " . ($lineNumber + 1) . ": \"{$trimmed}\"");
            }

            $copies = $matches[1] !== '' ? (int) $matches[1] : 1;
            $cardName = trim($matches[2]);
            $catalogId = $this->catalogIdsByName[mb_strtolower($cardName)] ?? null;

            if ($catalogId === null) {
                throw new GameStateException("Unrecognized card \"{$cardName}\" on {$sectionLabel} line " . ($lineNumber + 1) . '.');
            }

            for ($i = 0; $i < $copies; $i++) {
                $cardIds[] = $catalogId;
            }
        }

        return $cardIds;
    }
}
