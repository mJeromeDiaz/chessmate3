<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Symfony\Component\Uid\Uuid;

/**
 * One prepared move per user's position (docs/REPERTOIRE.md, "Un seul coup préparé"): the trash
 * (repertoire_trash), the former alternatives set aside in it with everything only reachable
 * through them (reason "migrated", restorable), the undo journal cleared (it still speaks of
 * alternatives), and the role column restricted to reference and reply.
 */
final class Version20260930105948 extends AbstractMigration
{
    private const INITIAL = 'rnbqkbnr/pppppppp/8/8/8/8/PPPPPPPP/RNBQKBNR w KQkq -';

    public function getDescription(): string
    {
        return 'Trash; alternatives set aside in it; one prepared move per position';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE repertoire_trash (id BINARY(16) NOT NULL, reason VARCHAR(16) NOT NULL, from_fen VARCHAR(92) CHARACTER SET ascii NOT NULL COLLATE `ascii_bin`, uci VARCHAR(5) CHARACTER SET ascii NOT NULL COLLATE `ascii_bin`, san VARCHAR(10) CHARACTER SET ascii NOT NULL COLLATE `ascii_bin`, path JSON NOT NULL, position_count INT UNSIGNED NOT NULL, move_count INT UNSIGNED NOT NULL, suite_rows JSON NOT NULL, created_at DATETIME NOT NULL, repertoire_id BINARY(16) NOT NULL, INDEX idx_repertoire_trash_repertoire_created (repertoire_id, created_at), INDEX IDX_63F8A98A1E61B789 (repertoire_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE repertoire_trash ADD CONSTRAINT FK_63F8A98A1E61B789 FOREIGN KEY (repertoire_id) REFERENCES repertoire (id) ON DELETE CASCADE');
    }

    public function postUp(Schema $schema): void
    {
        $repertoires = $this->connection->fetchFirstColumn("SELECT DISTINCT repertoire_id FROM repertoire_move WHERE role = 'alternative'");
        foreach ($repertoires as $repertoireId) {
            $this->setAlternativesAside((string) $repertoireId);
        }
        $this->connection->executeStatement('DELETE FROM repertoire_revision');
        $this->connection->executeStatement("ALTER TABLE repertoire_move ADD CONSTRAINT chk_repertoire_move_role CHECK (role IN ('reference', 'reply'))");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE repertoire_move DROP CHECK chk_repertoire_move_role');
        $this->addSql('ALTER TABLE repertoire_trash DROP FOREIGN KEY FK_63F8A98A1E61B789');
        $this->addSql('DROP TABLE repertoire_trash');
    }

    private function setAlternativesAside(string $repertoireId): void
    {
        $id = [$repertoireId];
        $types = [ParameterType::BINARY];
        $positions = [];
        foreach ($this->connection->fetchAllAssociative('SELECT id, fen, fen_hash, turn, depth, created_at FROM repertoire_position WHERE repertoire_id = ?', $id, $types) as $row) {
            $row['id'] = Uuid::fromBinary((string) $row['id'])->toRfc4122();
            $row['fen_hash'] = bin2hex((string) $row['fen_hash']);
            $positions[$row['id']] = $row;
        }
        $moves = [];
        foreach ($this->connection->fetchAllAssociative('SELECT id, from_position_id, to_position_id, uci, san, role, sort_order, comment, nags, canonical, created_at FROM repertoire_move WHERE repertoire_id = ? ORDER BY id', $id, $types) as $row) {
            foreach (['id', 'from_position_id', 'to_position_id'] as $column) {
                $row[$column] = Uuid::fromBinary((string) $row[$column])->toRfc4122();
            }
            $moves[$row['id']] = $row;
        }
        $root = array_values(array_filter($positions, static fn (array $p): bool => self::INITIAL === $p['fen']))[0]['id'] ?? null;
        if (null === $root) {
            return;
        }
        $alternatives = array_filter($moves, static fn (array $m): bool => 'alternative' === $m['role']);
        $leaving = [];
        foreach ($moves as $move) {
            $leaving[$move['from_position_id']][] = $move;
        }

        // What the root still reaches without the alternatives.
        $reachable = [$root => true];
        $queue = [$root];
        for ($i = 0; $i < \count($queue); ++$i) {
            foreach ($leaving[$queue[$i]] ?? [] as $move) {
                if ('alternative' !== $move['role'] && !isset($reachable[$move['to_position_id']])) {
                    $reachable[$move['to_position_id']] = true;
                    $queue[] = $move['to_position_id'];
                }
            }
        }

        $owner = [];
        $now = gmdate('Y-m-d H:i:s');
        foreach ($alternatives as $altId => $alternative) {
            $group = [];
            $stack = [];
            $to = $alternative['to_position_id'];
            if (!isset($reachable[$to]) && !isset($owner[$to])) {
                $owner[$to] = $altId;
                $stack[] = $to;
            }
            while ([] !== $stack) {
                $position = array_pop($stack);
                $group[] = $position;
                foreach ($leaving[$position] ?? [] as $move) {
                    $next = $move['to_position_id'];
                    if (!isset($reachable[$next]) && !isset($owner[$next])) {
                        $owner[$next] = $altId;
                        $stack[] = $next;
                    }
                }
            }
            $inside = array_flip($group);
            $groupMoves = array_values(array_filter($moves, static fn (array $m): bool => $m['id'] === $altId || isset($inside[$m['from_position_id']])));
            $external = [];
            foreach ($groupMoves as $move) {
                foreach (['from_position_id', 'to_position_id'] as $column) {
                    if (!isset($inside[$move[$column]])) {
                        $external[$move[$column]] = $positions[$move[$column]]['fen'];
                    }
                }
            }
            $rows = [
                // A set-aside alternative comes back as the prepared move of its position.
                'positions' => array_map(static fn (string $p): array => $positions[$p], $group),
                'moves' => array_map(static fn (array $m): array => ['role' => 'alternative' === $m['role'] ? 'reference' : $m['role']] + $m, $groupMoves),
                'external' => $external,
            ];

            $this->connection->insert('repertoire_trash', [
                'id' => Uuid::v7()->toBinary(),
                'repertoire_id' => $repertoireId,
                'reason' => 'migrated',
                'from_fen' => $positions[$alternative['from_position_id']]['fen'],
                'uci' => $alternative['uci'],
                'san' => $alternative['san'],
                'path' => json_encode($this->canonicalPath($alternative['from_position_id'], $root, $moves), \JSON_THROW_ON_ERROR),
                'position_count' => \count($group),
                'move_count' => \count($groupMoves),
                'suite_rows' => json_encode($rows, \JSON_THROW_ON_ERROR),
                'created_at' => $now,
            ]);
            $this->connection->executeStatement('DELETE FROM repertoire_move WHERE id IN (?)', [array_map(static fn (array $m): string => Uuid::fromString($m['id'])->toBinary(), $groupMoves)], [ArrayParameterType::BINARY]);
            if ([] !== $group) {
                $this->connection->executeStatement('DELETE FROM repertoire_position WHERE id IN (?)', [array_map(static fn (string $p): string => Uuid::fromString($p)->toBinary(), $group)], [ArrayParameterType::BINARY]);
            }
            foreach ($groupMoves as $move) {
                unset($moves[$move['id']]);
            }
        }
        $this->connection->executeStatement('UPDATE repertoire SET position_count = (SELECT COUNT(*) FROM repertoire_position WHERE repertoire_id = ?) WHERE id = ?', [$repertoireId, $repertoireId], [ParameterType::BINARY, ParameterType::BINARY]);
    }

    /**
     * @param array<string, array<string, mixed>> $moves
     *
     * @return list<string>
     */
    private function canonicalPath(string $position, string $root, array $moves): array
    {
        $path = [];
        $seen = [];
        while ($position !== $root && !isset($seen[$position])) {
            $seen[$position] = true;
            $in = array_values(array_filter($moves, static fn (array $m): bool => $m['to_position_id'] === $position && 1 === (int) $m['canonical']))[0] ?? null;
            if (null === $in) {
                break;
            }
            array_unshift($path, (string) $in['san']);
            $position = (string) $in['from_position_id'];
        }

        return $path;
    }
}
