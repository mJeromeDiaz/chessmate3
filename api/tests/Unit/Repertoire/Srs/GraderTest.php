<?php

declare(strict_types=1);

namespace App\Tests\Unit\Repertoire\Srs;

use App\Enum\Repertoire\Rating;
use App\Repertoire\Srs\Card;
use App\Repertoire\Srs\Fsrs;
use App\Repertoire\Srs\Grader;
use PHPUnit\Framework\TestCase;

final class GraderTest extends TestCase
{
    public function testAWrongMoveIsAgainAndARightOneIsRatedByItsThinkTime(): void
    {
        self::assertSame(Rating::Again, Grader::rate(false, 500));
        self::assertSame(Rating::Easy, Grader::rate(true, 0));
        self::assertSame(Rating::Easy, Grader::rate(true, 1999));
        self::assertSame(Rating::Good, Grader::rate(true, 2000));
        self::assertSame(Rating::Good, Grader::rate(true, 6000));
        self::assertSame(Rating::Hard, Grader::rate(true, 6001));
    }

    public function testARightAnswerUpdatesADueCardOnlyAndAWrongOneAlways(): void
    {
        $now = new \DateTimeImmutable('2026-01-01 09:00:00', new \DateTimeZone('UTC'));
        $reviewed = (new Fsrs(fuzz: false))->review(Card::fresh($now), Rating::Easy, $now);

        self::assertTrue(Grader::updatesCard(true, Card::fresh($now), $now), 'a new card is due');
        self::assertFalse(Grader::updatesCard(true, $reviewed, $now->modify('+1 day')));
        self::assertTrue(Grader::updatesCard(true, $reviewed, $now->modify('+8 days')));
        self::assertTrue(Grader::updatesCard(false, $reviewed, $now->modify('+1 day')));
    }
}
