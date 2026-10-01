<?php

declare(strict_types=1);

namespace App\Repertoire\Import;

use App\Entity\Repertoire\Import;
use App\Entity\User;
use App\Enum\Repertoire\Color;
use App\Enum\Repertoire\ImportStatus;
use App\Repertoire\Exception\LimitReachedException;
use App\Repertoire\Exception\RepertoireNotFoundException;
use App\Repertoire\Exception\StaleVersionException;
use App\Repertoire\Import\Message\AnalyzeImport;
use App\Repertoire\Import\Message\ApplyImport;
use App\Repertoire\Limits;
use App\Repository\Repertoire\ImportRepository;
use App\Repository\Repertoire\RepertoireRepository;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Uid\Uuid;

/**
 * The life of an import (docs/REPERTOIRE.md, "Import"): created from PGN text or an OpenBook backup
 * (JSON, recognized by its content: {@see OpenBookReader}), analysed, previewed
 * against a destination, applied. Small imports are analysed and applied during the request; big
 * ones by a worker (Messenger `async`), their progress stored with them
 * ({@see Limits::$syncImportBytes}, {@see Limits::$syncImportPositions}).
 *
 * @phpstan-type ApplyTarget array{repertoireId: Uuid}|array{name: string, color: Color}
 */
final readonly class ImportService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private ImportRepository $imports,
        private PgnAnalyzer $analyzer,
        private OpenBookReader $openBook,
        private RepertoireRepository $repertoires,
        private ImportApplier $applier,
        private Limits $limits,
        private ClockInterface $clock,
        private MessageBusInterface $bus,
        private Connection $connection,
    ) {
    }

    /**
     * @throws ImportRejectedException too large (nothing is stored then)
     */
    public function create(User $user, string $source, ?string $label, string $pgn): Import
    {
        if (\strlen($pgn) > $this->limits->maxImportBytes) {
            throw new ImportRejectedException(ImportRejectedException::TOO_LARGE, sprintf('%d bytes at most.', $this->limits->maxImportBytes));
        }
        if (Import::SOURCE_PGN === $source && OpenBookReader::recognizes($pgn)) {
            $source = Import::SOURCE_OPENBOOK;
        }
        $now = $this->now();
        $this->imports->purgeExpired($now);
        $import = new Import($user, $source, $label, $pgn, $now, $now->modify(sprintf('+%d hours', $this->limits->importTtlHours)));
        $this->entityManager->persist($import);
        $this->entityManager->flush();

        if (\strlen($pgn) <= $this->limits->syncImportBytes) {
            $this->analyze($import);
        } else {
            $this->bus->dispatch(new AnalyzeImport($import->getId()->toRfc4122()));
        }

        return $import;
    }

    /** Worker side of {@see AnalyzeImport}. */
    public function runAnalysis(Uuid $id): void
    {
        $import = $this->imports->find($id);
        if (null !== $import && ImportStatus::Analyzing === $import->getStatus()) {
            $this->analyze($import, true);
        }
    }

    /**
     * The user's import (404 when unknown, another user's, or expired).
     */
    public function get(User $user, Uuid $id): ?Import
    {
        return $this->imports->findOwned($id, $user, $this->now());
    }

    /**
     * The analysed import planned against a destination: an existing repertoire of the user, or a
     * new one of the given color.
     *
     * @param array<string, string> $choices
     *
     * @throws RepertoireNotFoundException
     */
    public function preview(Import $import, ?Uuid $repertoireId, Color $color, array $choices = []): ?ImportPlan
    {
        $tree = $import->getTree();
        if (ImportStatus::Analyzed !== $import->getStatus() || null === $tree) {
            return null;
        }

        return $this->applier->preview($import->getUser(), $this->tree($import, $tree, $repertoireId, $color), $repertoireId, $color, $choices);
    }

    /**
     * Applies the import now when it is small, else queues it for a worker.
     *
     * @param ApplyTarget           $target
     * @param array<string, string> $choices
     *
     * @throws ImportNotReadyException
     * @throws RepertoireNotFoundException
     * @throws LimitReachedException
     * @throws StaleVersionException
     * @throws \InvalidArgumentException invalid name
     */
    public function apply(Import $import, array $target, array $choices, ?int $baseVersion): Import
    {
        $tree = $import->getTree();
        if (ImportStatus::Analyzed !== $import->getStatus() || null === $tree) {
            throw new ImportNotReadyException();
        }
        $analyzed = $this->tree($import, $tree, $target['repertoireId'] ?? null, $target['color'] ?? Color::White);
        $plan = $this->applier->preview($import->getUser(), $analyzed, $target['repertoireId'] ?? null, $target['color'] ?? Color::White, $choices);
        if ($plan->positionsAfter > $this->limits->maxPositions) {
            throw new LimitReachedException('positions', $this->limits->maxPositions);
        }

        if ($plan->newPositions > $this->limits->syncImportPositions) {
            $import->queueApplication([
                'repertoireId' => isset($target['repertoireId']) ? $target['repertoireId']->toRfc4122() : null,
                'name' => $target['name'] ?? null,
                'color' => isset($target['color']) ? $target['color']->value : null,
                'choices' => $choices,
                'baseVersion' => $baseVersion,
            ]);
            $this->entityManager->flush();
            $this->bus->dispatch(new ApplyImport($import->getId()->toRfc4122()));

            return $import;
        }

        $repertoireId = $this->applier->apply($import->getUser(), $analyzed, $target, $choices, $baseVersion);
        $import->done($repertoireId);
        $this->entityManager->flush();

        return $import;
    }

    /** Worker side of {@see ApplyImport}: a refusal sends the import back to its preview. */
    public function runApplication(Uuid $id): void
    {
        $import = $this->imports->find($id);
        $request = $import?->getRequest();
        $tree = $import?->getTree();
        if (null === $import || ImportStatus::Applying !== $import->getStatus() || null === $request || null === $tree) {
            return;
        }
        $this->progress($import, 10);
        $repertoireId = \is_string($request['repertoireId'] ?? null) ? Uuid::fromString($request['repertoireId']) : null;
        $target = null !== $repertoireId
            ? ['repertoireId' => $repertoireId]
            : ['name' => \is_string($request['name'] ?? null) ? $request['name'] : '', 'color' => Color::tryFrom(\is_string($request['color'] ?? null) ? $request['color'] : '') ?? Color::White];
        $choices = [];
        foreach (\is_array($request['choices'] ?? null) ? $request['choices'] : [] as $fen => $uci) {
            if (\is_string($uci)) {
                $choices[(string) $fen] = $uci;
            }
        }
        $baseVersion = \is_int($request['baseVersion'] ?? null) ? $request['baseVersion'] : null;

        try {
            $applied = $this->applier->apply($import->getUser(), $this->tree($import, $tree, $target['repertoireId'] ?? null, $target['color'] ?? Color::White), $target, $choices, $baseVersion);
        } catch (LimitReachedException|StaleVersionException|RepertoireNotFoundException|\InvalidArgumentException $e) {
            $this->refused($id, match (true) {
                $e instanceof LimitReachedException => 'limit_'.$e->limit,
                $e instanceof StaleVersionException => 'stale',
                $e instanceof RepertoireNotFoundException => 'not_found',
                default => 'invalid_name',
            });

            return;
        }
        $import->done($applied);
        $this->entityManager->flush();
    }

    /**
     * The analysed tree for a destination: an OpenBook backup gives the side of the repertoire's
     * color (or of the new repertoire's).
     *
     * @throws RepertoireNotFoundException
     */
    private function tree(Import $import, string $stored, ?Uuid $repertoireId, Color $color): ImportedTree
    {
        if (Import::SOURCE_OPENBOOK !== $import->getSource()) {
            return ImportedTree::fromJson($stored);
        }
        if (null !== $repertoireId) {
            $color = $this->repertoires->findOwned($repertoireId, $import->getUser())?->getColor() ?? throw new RepertoireNotFoundException();
        }

        return ImportedTree::fromStored($stored, $color->value);
    }

    private function analyze(Import $import, bool $reportProgress = false): void
    {
        $progress = $reportProgress ? function (int $done, int $total) use ($import): void {
            $this->progress($import, intdiv($done * 90, max(1, $total)));
        } : null;
        try {
            if (Import::SOURCE_OPENBOOK === $import->getSource()) {
                [$white, $black, $suggested] = $this->openBook->read((string) $import->getPgn(), $progress);
                $import->analyzed(ImportedTree::sidesToJson($white, $black, $suggested));
            } else {
                $import->analyzed($this->analyzer->analyze((string) $import->getPgn(), $progress)->toJson());
            }
        } catch (ImportRejectedException $e) {
            $import->fail($e->reason, $e->pgnLine);
        }
        $this->entityManager->flush();
    }

    /**
     * Written at once, outside of any transaction: the user polls it.
     */
    private function progress(Import $import, int $progress): void
    {
        if ($progress === $import->getProgress()) {
            return;
        }
        $import->setProgress($progress);
        $this->connection->executeStatement('UPDATE repertoire_import SET progress = ? WHERE id = ?', [$import->getProgress(), $import->getId()->toBinary()], [ParameterType::INTEGER, ParameterType::BINARY]);
    }

    /**
     * After a refused application the entity manager may hold rolled-back entities (a new
     * repertoire): start from a clean one.
     */
    private function refused(Uuid $id, string $error): void
    {
        $this->entityManager->clear();
        $import = $this->imports->find($id);
        if (null !== $import) {
            $import->applicationRefused($error);
            $this->entityManager->flush();
        }
    }

    private function now(): \DateTimeImmutable
    {
        return $this->clock->now()->setTimezone(new \DateTimeZone('UTC'));
    }
}
