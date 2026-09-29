<?php

declare(strict_types=1);

namespace App\Tests\Unit\Woodpecker;

use App\Entity\User;
use App\Entity\Woodpecker\Set;
use App\Enum\Woodpecker\SetMode;
use App\Woodpecker\Mode\ProgressionInterface;
use App\Woodpecker\Mode\ProgressionRegistry;
use App\Woodpecker\Set\SetConfig;
use PHPUnit\Framework\TestCase;

final class ProgressionRegistryTest extends TestCase
{
    public function testResolvesTheProgressionOfTheSetsMode(): void
    {
        $classic = $this->createStub(ProgressionInterface::class);
        $classic->method('mode')->willReturn(SetMode::Classic);
        $light = $this->createStub(ProgressionInterface::class);
        $light->method('mode')->willReturn(SetMode::Light);

        self::assertSame($classic, (new ProgressionRegistry([$light, $classic]))->for(self::classicSet()));
    }

    public function testAModeWithoutProgressionIsAProgrammingError(): void
    {
        $this->expectException(\LogicException::class);
        (new ProgressionRegistry([]))->for(self::classicSet());
    }

    private static function classicSet(): Set
    {
        return new Set(new User(), 'Tactics', new SetConfig(50, 1000, 1500, [], 7, 28, 0.5, 1, 0, false), new \DateTimeImmutable());
    }
}
