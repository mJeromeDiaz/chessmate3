<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Position evaluation (docs/EVALUATION.md): a run now lasts 1, 2, 5 or 10 minutes, with no number
 * of positions nor time per position. The evaluation steps of the saved sessions, and those still
 * to play of the active ones, get the longest allowed length not above theirs (at least 1 minute)
 * and lose `count` and `seconds`.
 */
final class Version20261016090000 extends AbstractMigration
{
    /** EvaluationRules::RUN_MINUTES when written (a migration never follows the code). */
    private const RUN_MINUTES = [1, 2, 5, 10];

    public function getDescription(): string
    {
        return 'Evaluation: session steps of 1, 2, 5 or 10 minutes, without count and seconds';
    }

    public function up(Schema $schema): void
    {
        foreach ($this->connection->fetchAllAssociative('SELECT id, steps FROM training_session_plan') as $row) {
            $this->rewrite('training_session_plan', $row, false);
        }
        foreach ($this->connection->fetchAllAssociative("SELECT id, steps FROM training_session WHERE status = 'active'") as $row) {
            $this->rewrite('training_session', $row, true);
        }
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('The former number of positions and time per position are lost.');
    }

    /**
     * @param array<string, mixed> $row
     */
    private function rewrite(string $table, array $row, bool $pendingOnly): void
    {
        $steps = json_decode((string) $row['steps'], true, 512, \JSON_THROW_ON_ERROR);
        if (!\is_array($steps)) {
            return;
        }
        $changed = false;
        foreach ($steps as $i => $step) {
            if (!\is_array($step) || 'evaluation' !== ($step['module'] ?? null) || ($pendingOnly && 'pending' !== ($step['status'] ?? null))) {
                continue;
            }
            $minutes = \is_int($step['minutes'] ?? null) ? $step['minutes'] : 0;
            $allowed = array_filter(self::RUN_MINUTES, static fn (int $m): bool => $m <= $minutes);
            $settings = \is_array($step['settings'] ?? null) ? $step['settings'] : [];
            unset($settings['count'], $settings['seconds']);
            $steps[$i]['minutes'] = [] === $allowed ? self::RUN_MINUTES[0] : max($allowed);
            $steps[$i]['settings'] = $settings;
            $changed = true;
        }
        if ($changed) {
            $this->addSql(
                \sprintf('UPDATE %s SET steps = ? WHERE id = ?', $table),
                [json_encode($steps, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES), $row['id']],
            );
        }
    }
}
