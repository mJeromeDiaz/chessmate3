<?php

declare(strict_types=1);

namespace App\Tests\Functional\Puzzle;

use App\Enum\Puzzle\ThemeCategory;
use App\Puzzle\Theme\ThemeCatalog;
use App\Puzzle\Theme\ThemeSynchronizer;
use App\Repository\Catalog\ThemeRepository;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class ThemeSynchronizerTest extends KernelTestCase
{
    public function testSyncIsIdempotentAndKeepsIds(): void
    {
        $synchronizer = self::getContainer()->get(ThemeSynchronizer::class);
        $repository = self::getContainer()->get(ThemeRepository::class);
        $synchronizer->sync();
        $idsBefore = array_map(static fn ($t) => $t->getId(), $repository->findAllOrdered());

        self::assertSame(0, $synchronizer->sync());

        $themes = $repository->findAllOrdered();
        self::assertCount(\count(ThemeCatalog::THEMES), $themes);
        self::assertSame($idsBefore, array_map(static fn ($t) => $t->getId(), $themes));

        $mateIn1 = $repository->findOneBy(['key' => 'mateIn1']);
        self::assertNotNull($mateIn1);
        self::assertSame(ThemeCategory::Mates, $mateIn1->getCategory());
        self::assertSame('Mat en 1', $mateIn1->getLabelFr());
    }
}
