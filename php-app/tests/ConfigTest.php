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
}
