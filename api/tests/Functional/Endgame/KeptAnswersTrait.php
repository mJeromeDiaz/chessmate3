<?php

declare(strict_types=1);

namespace App\Tests\Functional\Endgame;

use App\Chess\Position\FenNormalizer;
use App\Endgame\Tablebase\Answer;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;

/**
 * Tablebase answers kept in the endgame database, as the browser's requests leave them while a
 * game is played (docs/ENDGAMES.md, § 2): the judge reads nothing else.
 */
trait KeptAnswersTrait
{
    /**
     * @param array<mixed> $body as Lichess answers (GET /standard)
     */
    private static function keep(string $fen, array $body): void
    {
        $key = (new FenNormalizer())->key($fen);
        /** @var Connection $endgame */
        $endgame = self::getContainer()->get('doctrine.dbal.endgame_connection');
        $endgame->executeStatement(
            'INSERT INTO endgame_tablebase (fen_hash, fen, answer, fetched_at, read_on) VALUES (?, ?, ?, NOW(), CURDATE()) ON DUPLICATE KEY UPDATE answer = VALUES(answer)',
            [$key->hash, $key->fen, json_encode(Answer::fromLichess($body)->toStored(), \JSON_THROW_ON_ERROR)],
            [ParameterType::BINARY, ParameterType::STRING, ParameterType::STRING],
        );
    }

    /**
     * A won position (the player to move) where `$move` keeps the win; `$spoiling` draws.
     *
     * @return array<mixed>
     */
    private static function winWith(string $move, ?string $spoiling = null, int $dtm = 21): array
    {
        return ['category' => 'win', 'dtm' => $dtm, 'moves' => [
            ['uci' => $move, 'category' => 'loss', 'dtm' => -($dtm - 1)],
            ...(null === $spoiling ? [] : [['uci' => $spoiling, 'category' => 'draw']]),
        ]];
    }

    /**
     * A drawn position (the player to move) where `$move` keeps the draw; `$losing` loses.
     *
     * @return array<mixed>
     */
    private static function drawWith(string $move, ?string $losing = null): array
    {
        return ['category' => 'draw', 'moves' => [
            ['uci' => $move, 'category' => 'draw'],
            ...(null === $losing ? [] : [['uci' => $losing, 'category' => 'win', 'dtm' => 25]]),
        ]];
    }
}
