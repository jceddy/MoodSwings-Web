<?php

declare(strict_types=1);

namespace MoodSwings\Tests\Rules;

use MoodSwings\Rules\Effects\CharityEffect;
use MoodSwings\Rules\EffectRegistry;
use MoodSwings\Rules\Exceptions\EffectNotImplementedException;
use PHPUnit\Framework\TestCase;

final class EffectRegistryTest extends TestCase
{
    public function testRegisterAndRetrieve(): void
    {
        $registry = new EffectRegistry();
        $effect = new CharityEffect();

        $registry->register('charity', $effect);

        self::assertTrue($registry->has('charity'));
        self::assertSame($effect, $registry->for('charity'));
    }

    public function testUnregisteredEffectKeyIsNotRegistered(): void
    {
        $registry = new EffectRegistry();

        self::assertFalse($registry->has('nonexistent'));
    }

    public function testForThrowsForUnregisteredEffectKey(): void
    {
        $registry = new EffectRegistry();

        $this->expectException(EffectNotImplementedException::class);
        $registry->for('nonexistent');
    }
}
