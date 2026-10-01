<?php

declare(strict_types=1);

namespace App\Repertoire\Import;

use App\Entity\Repertoire\Repertoire;
use App\Entity\User;
use App\Enum\Repertoire\Color;
use App\Repertoire\Exception\LimitReachedException;
use App\Repertoire\Exception\RepertoireNotFoundException;
use App\Repertoire\Exception\StaleVersionException;
use App\Repertoire\Graph\GraphEditor;
use App\Repertoire\RepertoireManager;
use App\Repertoire\Transaction;
use App\Repository\Repertoire\RepertoireRepository;
use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;

/**
 * Previews and applies an analysed import, into a new repertoire or an existing one of the user
 * (docs/REPERTOIRE.md, "Import"). Applying is one change of the repertoire: one undo step. A new
 * repertoire is created in the same transaction: nothing remains if the import is refused.
 */
final readonly class ImportApplier
{
    public function __construct(
        private ImportPlanner $planner,
        private GraphEditor $editor,
        private RepertoireManager $manager,
        private RepertoireRepository $repertoires,
        private Connection $connection,
        private Transaction $transaction,
    ) {
    }

    /**
     * @param array<string, string> $choices normalized FEN => UCI of the chosen reference
     *
     * @throws RepertoireNotFoundException
     */
    public function preview(User $user, ImportedTree $tree, ?Uuid $repertoireId, Color $color, array $choices = []): ImportPlan
    {
        if (null === $repertoireId) {
            return $this->planner->plan($tree, $color, Target::empty(), $choices);
        }
        $repertoire = $this->repertoires->findOwned($repertoireId, $user) ?? throw new RepertoireNotFoundException();

        return $this->planner->plan($tree, $repertoire->getColor(), Target::load($this->connection, $repertoire), $choices);
    }

    /**
     * @param array{repertoireId: Uuid}|array{name: string, color: Color} $target
     * @param array<string, string>                                        $choices
     *
     * @return Uuid the repertoire imported into
     *
     * @throws RepertoireNotFoundException
     * @throws LimitReachedException
     * @throws StaleVersionException
     * @throws \InvalidArgumentException invalid name
     */
    public function apply(User $user, ImportedTree $tree, array $target, array $choices = [], ?int $baseVersion = null): Uuid
    {
        $plan = fn (Repertoire $repertoire): ImportPlan => $this->planner->plan($tree, $repertoire->getColor(), Target::load($this->connection, $repertoire), $choices);

        if (isset($target['repertoireId'])) {
            $this->editor->import($user, $target['repertoireId'], $plan, $baseVersion);

            return $target['repertoireId'];
        }

        return $this->transaction->run(function () use ($user, $target, $plan): Uuid {
            $repertoire = $this->manager->create($user, $target['name'], $target['color']);
            $this->editor->import($user, $repertoire->getId(), $plan);

            return $repertoire->getId();
        });
    }
}
