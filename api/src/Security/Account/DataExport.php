<?php

declare(strict_types=1);

namespace App\Security\Account;

use App\Entity\AuthIdentity;
use App\Entity\TrustedDevice;
use App\Entity\User;
use App\Repertoire\Pgn\Exporter as PgnExporter;
use App\Repository\Repertoire\RepertoireRepository;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Psr\Clock\ClockInterface;

/**
 * The export of a user's data (docs/AUTH.md, profile): a ZIP of JSON files, one per domain, and a
 * PGN file per repertoire, written to a temporary file the caller sends then deletes. Explicit
 * columns only: never a password hash, a token, its hash or an encrypted secret. Identifiers are
 * UUIDs, instants are UTC (ISO 8601 with Z), keys are the database's column names.
 */
final readonly class DataExport
{
    /** UTC instants as MySQL returns them. */
    private const DATETIME = '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/';

    private const README = <<<'TXT'
        Export de vos données ChessMate
        ===============================

        Généré le %s (UTC) pour le compte %s.

        - profil.json : votre compte (adresse, nom, pseudo, préférences), les comptes liés (Google,
          Lichess) et les appareils de confiance. Aucun mot de passe ni jeton.
        - puzzles.json : votre classement, son historique et chacune de vos tentatives (le puzzle est
          désigné par son identifiant Lichess : https://lichess.org/training/<identifiant>).
        - woodpecker.json : vos sets, leurs puzzles, cycles, tentatives et agrandissements.
        - repertoires.json : vos répertoires, leurs cartes de révision (FSRS), vos réponses et vos tests ;
          repertoires/*.pgn : chaque répertoire au format PGN.
        - entrainement.json : vos séances chronométrées, sessions et sessions enregistrées.
        - activite.json : le journal de vos exercices terminés.

        Les dates sont en UTC (ISO 8601), les identifiants des UUID.
        TXT;

    public function __construct(
        private Connection $connection,
        private RepertoireRepository $repertoires,
        private PgnExporter $pgn,
        private ClockInterface $clock,
    ) {
    }

    /**
     * Writes the ZIP and returns its path (a temporary file: the caller deletes it).
     */
    public function build(User $user): string
    {
        $path = tempnam(sys_get_temp_dir(), 'chessmate-export-');
        if (false === $path) {
            throw new \RuntimeException('No temporary file for the export.');
        }
        $zip = new \ZipArchive();
        if (true !== $zip->open($path, \ZipArchive::OVERWRITE)) {
            throw new \RuntimeException('Cannot write the export.');
        }
        $id = $user->getId()->toBinary();

        $zip->addFromString('LISEZMOI.txt', \sprintf(self::README, $this->now(), $user->getEmail() ?? $user->getId()->toRfc4122()));
        $zip->addFromString('profil.json', self::json($this->profile($user)));
        $zip->addFromString('puzzles.json', self::json([
            'rating' => $this->rows('SELECT rating, deviation, volatility, rated_count, last_rated_at, source, updated_at FROM puzzle_rating WHERE user_id = ?', $id)[0] ?? null,
            'ratingHistory' => $this->rows('SELECT reason, rating_before, rating_after, deviation_after, created_at FROM puzzle_rating_change WHERE user_id = ? ORDER BY created_at', $id),
            'attempts' => $this->rows(
                'SELECT BIN_TO_UUID(a.id) AS id, p.lichess_id AS puzzle, a.rated, a.status, a.started_at, a.submitted_at, a.duration_ms, a.moves,
                        a.mistakes, a.hint_level, a.solution_shown, BIN_TO_UUID(a.training_run_id) AS training_run_id
                   FROM puzzle_attempt a JOIN puzzle p ON p.id = a.puzzle_id
                  WHERE a.user_id = ? ORDER BY a.started_at',
                $id,
                ['moves'],
            ),
        ]));
        $zip->addFromString('woodpecker.json', self::json([
            'sets' => $this->rows(
                'SELECT BIN_TO_UUID(id) AS id, name, mode, status, puzzle_count, rating_min, rating_max, themes, cycle_count, first_cycle_days,
                        reduction_factor, min_cycle_days, rest_days, shuffle, created_at, paused_at, completed_at, abandoned_at, archived_at
                   FROM woodpecker_set WHERE user_id = ? ORDER BY created_at',
                $id,
                ['themes'],
            ),
            'setPuzzles' => $this->rows(
                'SELECT BIN_TO_UUID(sp.set_id) AS set_id, sp.position, p.lichess_id AS puzzle
                   FROM woodpecker_set_puzzle sp JOIN woodpecker_set s ON s.id = sp.set_id JOIN puzzle p ON p.id = sp.puzzle_id
                  WHERE s.user_id = ? ORDER BY s.created_at, sp.position',
                $id,
            ),
            'cycles' => $this->rows(
                'SELECT BIN_TO_UUID(c.id) AS id, BIN_TO_UUID(c.set_id) AS set_id, c.number, c.run, c.status, c.duration_days, c.available_at,
                        c.deadline_at, c.completed_at, c.lost_at
                   FROM woodpecker_cycle c JOIN woodpecker_set s ON s.id = c.set_id
                  WHERE s.user_id = ? ORDER BY c.available_at',
                $id,
            ),
            'attempts' => $this->rows(
                'SELECT BIN_TO_UUID(a.cycle_id) AS cycle_id, a.order_index, p.lichess_id AS puzzle, a.status, a.started_at, a.submitted_at,
                        a.duration_ms, a.moves, a.mistakes, a.hint_level, a.solution_shown, BIN_TO_UUID(a.training_run_id) AS training_run_id
                   FROM woodpecker_attempt a JOIN woodpecker_cycle c ON c.id = a.cycle_id JOIN woodpecker_set s ON s.id = c.set_id
                   JOIN puzzle p ON p.id = a.puzzle_id
                  WHERE s.user_id = ? ORDER BY a.started_at',
                $id,
                ['moves'],
            ),
            'growths' => $this->rows(
                'SELECT BIN_TO_UUID(g.set_id) AS set_id, g.round, g.added, g.puzzle_count, g.occurred_at
                   FROM woodpecker_set_growth g JOIN woodpecker_set s ON s.id = g.set_id
                  WHERE s.user_id = ? ORDER BY g.occurred_at',
                $id,
            ),
        ]));
        $this->addRepertoires($zip, $user, $id);
        $zip->addFromString('entrainement.json', self::json([
            'runs' => $this->rows(
                'SELECT BIN_TO_UUID(id) AS id, module, subject_type, BIN_TO_UUID(subject_id) AS subject_id, config, budget_seconds, status,
                        started_at, expires_at, closed_at, close_reason, summary, BIN_TO_UUID(parent_id) AS session_id
                   FROM training_run WHERE user_id = ? ORDER BY started_at',
                $id,
                ['config', 'summary'],
            ),
            'sessions' => $this->rows(
                'SELECT BIN_TO_UUID(id) AS id, title, description, steps, status, started_at, expires_at, closed_at, BIN_TO_UUID(plan_id) AS plan_id
                   FROM training_session WHERE user_id = ? ORDER BY started_at',
                $id,
                ['steps'],
            ),
            'plans' => $this->rows(
                'SELECT BIN_TO_UUID(id) AS id, title, description, steps, repetition, time, weekdays, public, reminder_enabled, reminder_channels,
                        reminder_minutes, calendar_enabled, created_at, updated_at
                   FROM training_session_plan WHERE user_id = ? ORDER BY created_at',
                $id,
                ['steps', 'weekdays', 'reminder_channels'],
            ),
        ]));
        $zip->addFromString('activite.json', self::json([
            'entries' => $this->rows(
                'SELECT exercise_type, success, duration_ms, item_count, source_type, source_id, occurred_at, local_date, timezone, metadata
                   FROM activity_log_entry WHERE user_id = ? ORDER BY occurred_at',
                $id,
                ['metadata'],
            ),
        ]));

        if (!$zip->close()) {
            throw new \RuntimeException('Cannot write the export.');
        }

        return $path;
    }

    /** The download's name: chessmate-export-2026-10-05.zip. */
    public function fileName(): string
    {
        return \sprintf('chessmate-export-%s.zip', $this->clock->now()->format('Y-m-d'));
    }

    /**
     * @return array<string, mixed>
     */
    private function profile(User $user): array
    {
        return [
            'id' => $user->getId()->toRfc4122(),
            'email' => $user->getEmail(),
            'emailVerifiedAt' => $user->getEmailVerifiedAt()?->format(\DATE_ATOM),
            'createdAt' => $user->getCreatedAt()->format(\DATE_ATOM),
            'hasPassword' => $user->hasPassword(),
            'displayName' => $user->getDisplayName(),
            'handle' => $user->getHandle(),
            'avatar' => $user->getAvatar()?->value,
            'timezone' => $user->getTimezone(),
            'theme' => $user->getTheme()?->value,
            'boardTheme' => $user->getBoardTheme()->value,
            'moveSound' => $user->hasMoveSound(),
            'publicProfile' => $user->isPublicProfile(),
            'deletionScheduledAt' => $user->getDeletionScheduledAt()?->format(\DATE_ATOM),
            'linkedAccounts' => array_values(array_map(static fn (AuthIdentity $identity): array => [
                'provider' => $identity->getProvider()->value,
                'providerUserId' => $identity->getProviderUserId(),
                'providerEmail' => $identity->getProviderEmail(),
                'linkedAt' => $identity->getCreatedAt()->format(\DATE_ATOM),
            ], $user->getAuthIdentities()->toArray())),
            'trustedDevices' => array_values(array_map(static fn (TrustedDevice $device): array => [
                'label' => $device->getLabel(),
                'createdAt' => $device->getCreatedAt()->format(\DATE_ATOM),
                'lastUsedAt' => $device->getLastUsedAt()?->format(\DATE_ATOM),
                'expiresAt' => $device->getExpiresAt()->format(\DATE_ATOM),
                'revokedAt' => $device->getRevokedAt()?->format(\DATE_ATOM),
            ], $user->getTrustedDevices()->toArray())),
        ];
    }

    private function addRepertoires(\ZipArchive $zip, User $user, string $id): void
    {
        $files = [];
        foreach ($this->repertoires->findByUser($user) as $i => $repertoire) {
            // Two repertoires may have the same name: the file names are numbered.
            $file = \sprintf('repertoires/%02d-%s', $i + 1, PgnExporter::fileName($repertoire));
            $zip->addFromString($file, $this->pgn->export($repertoire));
            $files[$repertoire->getId()->toRfc4122()] = $file;
        }
        $repertoires = array_map(
            static fn (array $row): array => [...$row, 'pgn' => \is_string($row['id']) ? ($files[$row['id']] ?? null) : null],
            $this->rows('SELECT BIN_TO_UUID(id) AS id, name, color, created_at, updated_at FROM repertoire WHERE user_id = ? ORDER BY created_at', $id),
        );

        $zip->addFromString('repertoires.json', self::json([
            'repertoires' => $repertoires,
            'cards' => $this->rows(
                'SELECT BIN_TO_UUID(c.repertoire_id) AS repertoire_id, c.fen, c.uci, c.state, c.step, c.stability, c.difficulty, c.due,
                        c.last_review, c.reps, c.lapses, c.created_at
                   FROM repertoire_card c JOIN repertoire r ON r.id = c.repertoire_id
                  WHERE r.user_id = ? ORDER BY c.created_at',
                $id,
            ),
            'reviews' => $this->rows(
                'SELECT BIN_TO_UUID(c.repertoire_id) AS repertoire_id, c.fen, c.uci, v.played_uci, v.correct, v.rating, v.think_ms,
                        v.reviewed_at, BIN_TO_UUID(v.run_id) AS training_run_id
                   FROM repertoire_review v JOIN repertoire_card c ON c.id = v.card_id JOIN repertoire r ON r.id = c.repertoire_id
                  WHERE r.user_id = ? ORDER BY v.reviewed_at',
                $id,
            ),
            'tests' => $this->rows(
                'SELECT BIN_TO_UUID(repertoire_id) AS repertoire_id, BIN_TO_UUID(segment_id) AS segment_id, BIN_TO_UUID(unit_id) AS unit_id, unit,
                        presentation_rank, round, status, first_error_ply, positions_graded, moves, label, start_fen, started_at, finished_at,
                        duration_ms, BIN_TO_UUID(run_id) AS training_run_id
                   FROM repertoire_presentation WHERE user_id = ? ORDER BY started_at',
                $id,
                ['moves', 'label'],
            ),
        ]));
    }

    /**
     * Rows of a query on the user's id: JSON columns decoded, UTC instants in ISO 8601.
     *
     * @param list<string> $jsonColumns
     *
     * @return list<array<string, mixed>>
     */
    private function rows(string $sql, string $userId, array $jsonColumns = []): array
    {
        $rows = $this->connection->fetchAllAssociative($sql, [$userId], [ParameterType::BINARY]);
        foreach ($rows as $i => $row) {
            foreach ($row as $column => $value) {
                if (\is_string($value) && \in_array($column, $jsonColumns, true)) {
                    $rows[$i][$column] = json_decode($value, true, flags: \JSON_THROW_ON_ERROR);
                } elseif (\is_string($value) && 1 === preg_match(self::DATETIME, $value)) {
                    $rows[$i][$column] = str_replace(' ', 'T', $value).'Z';
                }
            }
        }

        return $rows;
    }

    private function now(): string
    {
        return $this->clock->now()->setTimezone(new \DateTimeZone('UTC'))->format('d/m/Y H:i');
    }

    private static function json(mixed $data): string
    {
        return json_encode($data, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR | \JSON_PRESERVE_ZERO_FRACTION);
    }
}
