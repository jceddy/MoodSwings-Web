<?php

declare(strict_types=1);

namespace MoodSwings\Tests\Rules;

/**
 * A small hand-picked slice of the real cards catalog (same ids/values as
 * database/migrations/0003_create_card_catalog.sql, post-0005 fix) used to
 * build BoardState fixtures in tests without needing a database. Kept in
 * sync with the real values on purpose, so these tests double as a check
 * that the engine's numbers agree with the catalog's.
 */
trait CatalogFixture
{
    /** @return array<int, array{color:string,rarity:string,baseValue:int,altValue:?int,effectKey:string,hasToPlay:bool,hasWhileInPlay:bool,hasAfterPlaying:bool,rulesText:string}> */
    private function sampleCatalog(): array
    {
        return [
            2 => $this->row('white', 'uncommon', 2, null, 'benevolence', false, false, true),
            3 => $this->row('white', 'common', 1, null, 'charity', false, false, true),
            4 => $this->row('white', 'common', 3, 5, 'chivalry', false, true, false),
            5 => $this->row('white', 'common', 4, null, 'complacency', false, false, false),
            6 => $this->row('white', 'uncommon', 2, null, 'conviction', false, false, true),
            7 => $this->row('white', 'common', 1, null, 'courage', false, false, true),
            8 => $this->row('white', 'common', 3, 5, 'dignity', false, false, true),
            9 => $this->row('white', 'common', 6, 3, 'discipline', false, true, false),
            12 => $this->row('white', 'uncommon', 3, null, 'faith', false, false, true),
            13 => $this->row('white', 'uncommon', 2, null, 'friendliness', false, false, true),
            14 => $this->row('white', 'uncommon', 2, null, 'guilt', false, false, true),
            17 => $this->row('white', 'uncommon', 2, null, 'kindness', false, false, true),
            19 => $this->row('white', 'rare', 1, null, 'meekness', false, false, true),
            20 => $this->row('white', 'common', 1, null, 'pacifism', false, false, true),
            23 => $this->row('white', 'uncommon', 2, null, 'repentance', false, false, true),
            25 => $this->row('white', 'rare', 3, null, 'shame', false, false, true),
            27 => $this->row('blue', 'common', 6, 3, 'ambivalence', false, true, false),
            28 => $this->row('blue', 'common', 2, null, 'anxiety', false, false, true),
            32 => $this->row('blue', 'rare', 0, null, 'creativity', false, false, false),
            40 => $this->row('blue', 'mythic', 0, null, 'guile', true, false, true),
            42 => $this->row('blue', 'uncommon', 3, null, 'imagination', false, true, true),
            54 => $this->row('black', 'uncommon', 3, null, 'angst', false, false, true),
            55 => $this->row('black', 'common', 4, null, 'apathy', false, false, false),
            56 => $this->row('black', 'uncommon', 6, null, 'betrayal', false, false, true),
            57 => $this->row('black', 'uncommon', 0, null, 'bitterness', false, false, true),
            64 => $this->row('black', 'rare', 0, null, 'envy', true, true, false),
            66 => $this->row('black', 'common', 0, null, 'hate', false, false, true),
            74 => $this->row('black', 'mythic', 0, null, 'sadness', false, true, false),
            75 => $this->row('black', 'common', 6, null, 'self_loathing', true, false, false),
            76 => $this->row('black', 'common', 1, null, 'spite', false, false, true),
            79 => $this->row('black', 'mythic', 0, null, 'vanity', false, true, false),
            80 => $this->row('red', 'uncommon', 0, null, 'anger', false, false, true),
            84 => $this->row('red', 'common', 3, null, 'bravado', false, false, true),
            91 => $this->row('red', 'uncommon', 4, null, 'fury', false, false, true),
            98 => $this->row('red', 'uncommon', 2, null, 'rage', false, false, true),
            99 => $this->row('red', 'uncommon', 2, null, 'rebellion', false, false, true),
            101 => $this->row('red', 'common', 2, null, 'shock', false, false, true),
            104 => $this->row('red', 'common', 3, 5, 'triumph', false, true, false),
            105 => $this->row('red', 'rare', 0, null, 'wrath', false, false, true),
            106 => $this->row('red', 'common', 3, null, 'zeal', false, false, true),
            114 => $this->row('green', 'uncommon', 2, null, 'eagerness', false, false, true),
            118 => $this->row('green', 'uncommon', 3, 7, 'fascination', false, false, true),
            120 => $this->row('green', 'common', 6, null, 'generosity', false, false, true),
            133 => $this->row('green', 'mythic', 0, null, 'wonder', false, true, true),
        ];
    }

    /** @return array{color:string,rarity:string,baseValue:int,altValue:?int,effectKey:string,hasToPlay:bool,hasWhileInPlay:bool,hasAfterPlaying:bool,rulesText:string} */
    private function row(
        string $color,
        string $rarity,
        int $baseValue,
        ?int $altValue,
        string $effectKey,
        bool $hasToPlay,
        bool $hasWhileInPlay,
        bool $hasAfterPlaying,
    ): array {
        return [
            'color' => $color,
            'rarity' => $rarity,
            'baseValue' => $baseValue,
            'altValue' => $altValue,
            'effectKey' => $effectKey,
            'hasToPlay' => $hasToPlay,
            'hasWhileInPlay' => $hasWhileInPlay,
            'hasAfterPlaying' => $hasAfterPlaying,
            'rulesText' => '',
        ];
    }
}
