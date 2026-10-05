<?php

declare(strict_types=1);

namespace App\Tests\Functional\Puzzle;

use App\Entity\Puzzle\Attempt;
use Doctrine\DBAL\Connection;

/**
 * Attempts keep the puzzle's id and a copy of its themes (docs/DEPLOY_OVH.md, § 3): the catalogue
 * lives in another database, and the statistics by theme read the copy.
 */
final class CatalogLinkTest extends PuzzleWebTestCase
{
    public function testAnAttemptCopiesThePuzzleThemesWhenItStarts(): void
    {
        $user = $this->createUser('alice@example.com');

        $started = $this->startAttempt($user);

        $puzzle = $this->puzzles[$started['puzzle']['id']];
        $attempt = $this->entityManager->getRepository(Attempt::class)->find($started['id']);
        self::assertInstanceOf(Attempt::class, $attempt);
        self::assertSame($puzzle->getId(), $attempt->getPuzzleId());
        self::assertSame($puzzle->getThemes(), $attempt->getPuzzleThemes());
        self::assertNotSame([], $attempt->getPuzzleThemes());
    }

    /**
     * A snapshot: a puzzle re-tagged later (a new Lichess export) keeps its old themes on past
     * attempts, and the history filter follows the copy.
     */
    public function testTheCopyIsASnapshot(): void
    {
        $user = $this->createUser('alice@example.com');
        $started = $this->startAttempt($user);
        $response = $this->api('POST', '/api/puzzles/attempts/'.$started['id'].'/submission', $user, [
            'moves' => self::playerMoves($started),
            'hintLevel' => 0,
            'solutionShown' => false,
        ]);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        $theme = $this->puzzles[$started['puzzle']['id']]->getThemes()[0];

        self::getContainer()->get(Connection::class)->executeStatement(
            'UPDATE puzzle SET themes = JSON_ARRAY(\'retagged\') WHERE id = :id',
            ['id' => $this->puzzles[$started['puzzle']['id']]->getId()],
        );

        $byOldTheme = $this->historyJson($this->api('GET', '/api/puzzles/attempts?theme='.$theme, $user));
        self::assertSame(1, $byOldTheme['totalItems']);
        self::assertSame(0, $this->historyJson($this->api('GET', '/api/puzzles/attempts?theme=retagged', $user))['totalItems']);
    }
}
