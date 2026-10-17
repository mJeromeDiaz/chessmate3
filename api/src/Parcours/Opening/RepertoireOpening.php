<?php

declare(strict_types=1);

namespace App\Parcours\Opening;

use App\Entity\Repertoire\Repertoire;
use App\Enum\Repertoire\Color;
use App\Parcours\ParcoursRules;
use App\Puzzle\Opening\OpeningFamily;
use App\Puzzle\Selection\RandomSeeker;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;

/**
 * The opening of a repertoire, for the puzzles of a parcours (docs/PARCOURS.md): the catalogue
 * family most present among its named positions that has enough puzzles for its side.
 */
final readonly class RepertoireOpening
{
    public function __construct(
        private Connection $connection,
        private RandomSeeker $seeker,
    ) {
    }

    /**
     * @return array{family: string, color: string, name: string}|null the family's tag, the player's side ("w", "b") and a readable name; null when none has enough puzzles
     */
    public function of(Repertoire $repertoire): ?array
    {
        $weights = [];
        foreach ($this->connection->fetchAllAssociative(
            'SELECT o.name, COUNT(*) AS n
               FROM repertoire_position p
               JOIN repertoire_opening o ON o.epd_hash = p.fen_hash
              WHERE p.repertoire_id = ?
              GROUP BY o.name',
            [$repertoire->getId()->toBinary()],
            [ParameterType::BINARY],
        ) as $row) {
            if (!\is_string($row['name'])) {
                continue;
            }
            $family = trim(explode(':', $row['name'], 2)[0]);
            $tag = OpeningFamily::tag($family);
            if ('' === $tag) {
                continue;
            }
            $weights[$tag] ??= ['name' => $family, 'weight' => 0];
            $weights[$tag]['weight'] += is_numeric($row['n']) ? (int) $row['n'] : 0;
        }
        uasort($weights, static fn (array $a, array $b): int => $b['weight'] <=> $a['weight']);
        $color = Color::White === $repertoire->getColor() ? 'w' : 'b';
        foreach ($weights as $tag => $family) {
            $tag = (string) $tag;
            if ($this->seeker->countOpening($tag, $color, ParcoursRules::ANY_RATING[0], ParcoursRules::ANY_RATING[1]) >= ParcoursRules::MIN_OPENING_PUZZLES) {
                return ['family' => $tag, 'color' => $color, 'name' => $family['name']];
            }
        }

        return null;
    }
}
