<?php

declare(strict_types=1);

namespace MoodSwings\Tests;

use MoodSwings\Config;
use PHPUnit\Framework\TestCase;

final class ConfigTest extends TestCase
{
    public function testReturnsDefaultWhenKeyIsUnset(): void
    {
        $this->assertSame('fallback', Config::get('MOODSWINGS_UNSET_KEY', 'fallback'));
    }

    public function testGetBoolReturnsDefaultWhenKeyIsUnset(): void
    {
        self::assertFalse(Config::getBool('MOODSWINGS_UNSET_BOOL_KEY'));
        self::assertTrue(Config::getBool('MOODSWINGS_UNSET_BOOL_KEY', true));
    }

    /** @dataProvider truthyValues */
    public function testGetBoolRecognizesTruthyValuesCaseInsensitively(string $value): void
    {
        putenv("MOODSWINGS_TEST_BOOL_KEY={$value}");
        try {
            self::assertTrue(Config::getBool('MOODSWINGS_TEST_BOOL_KEY'));
        } finally {
            putenv('MOODSWINGS_TEST_BOOL_KEY');
        }
    }

    public static function truthyValues(): array
    {
        return [['1'], ['true'], ['True'], ['YES'], ['on']];
    }

    public function testGetBoolTreatsAnyOtherValueAsFalse(): void
    {
        putenv('MOODSWINGS_TEST_BOOL_KEY=nope');
        try {
            self::assertFalse(Config::getBool('MOODSWINGS_TEST_BOOL_KEY', true));
        } finally {
            putenv('MOODSWINGS_TEST_BOOL_KEY');
        }
    }
}
