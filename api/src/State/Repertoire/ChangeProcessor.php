<?php

declare(strict_types=1);

namespace App\State\Repertoire;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\Repertoire\AddMoveInput;
use App\ApiResource\Repertoire\AnnotateInput;
use App\ApiResource\Repertoire\ReplaceMoveInput;
use App\ApiResource\Repertoire\RestoreInput;
use App\ApiResource\Repertoire\Change;
use App\ApiResource\Repertoire\ChangeInput;
use App\Chess\InvalidPositionException;
use App\Repertoire\Exception\IllegalMoveException;
use App\Repertoire\Exception\InvalidAnnotationException;
use App\Repertoire\Exception\LimitReachedException;
use App\Repertoire\Exception\MoveNotFoundException;
use App\Repertoire\Exception\NothingToUndoException;
use App\Repertoire\Exception\PositionNotFoundException;
use App\Repertoire\Exception\PositionOccupiedException;
use App\Repertoire\Exception\RestoreImpossibleException;
use App\Repertoire\Exception\TrashNotFoundException;
use App\Repertoire\Exception\RepeatedPositionException;
use App\Repertoire\Exception\RepertoireNotFoundException;
use App\Repertoire\Exception\StaleVersionException;
use App\Repertoire\Graph\Change as GraphChange;
use App\Repertoire\Graph\GraphEditor;
use App\Repertoire\Graph\GraphReader;
use App\Repository\Repertoire\RepertoireRepository;
use App\Security\AuthenticatedUser;
use App\Security\RateLimit\RateLimitGuard;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Uid\Uuid;

/**
 * Every change of a repertoire's graph (docs/REPERTOIRE.md): 404 unknown repertoire, move or
 * position (another user's included), 422 illegal move, repeated position, limit or annotation,
 * 409 stale baseVersion or nothing to undo, 429.
 *
 * @implements ProcessorInterface<ChangeInput, Change>
 */
final class ChangeProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly GraphEditor $editor,
        private readonly GraphReader $reader,
        private readonly RepertoireRepository $repertoires,
        private readonly AuthenticatedUser $authenticatedUser,
        private readonly RateLimitGuard $rateLimitGuard,
        private readonly RateLimiterFactory $repertoireEditLimiter,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): Change
    {
        $user = $this->authenticatedUser->get();
        $this->rateLimitGuard->consume($this->repertoireEditLimiter, $user->getId()->toRfc4122());
        $id = RepertoireIds::fromUri($uriVariables, 'id') ?? throw new NotFoundHttpException();
        $moveId = RepertoireIds::fromUri($uriVariables, 'moveId');
        $move = static fn (): Uuid => $moveId ?? throw new NotFoundHttpException();

        try {
            $change = match ($operation->getName()) {
                'repertoire_move_add' => $data instanceof AddMoveInput
                    ? $this->editor->addMove($user, $id, Uuid::fromString($data->fromPositionId), $data->uci, $data->baseVersion)
                    : throw new \LogicException('Unexpected input.'),
                'repertoire_move_replace' => $data instanceof ReplaceMoveInput
                    ? $this->editor->replace($user, $id, $move(), $data->uci, $data->baseVersion)
                    : throw new \LogicException('Unexpected input.'),
                'repertoire_trash_restore' => $data instanceof RestoreInput
                    ? $this->editor->restore($user, $id, (string) (RepertoireIds::fromUri($uriVariables, 'trashId') ?? throw new NotFoundHttpException()), $data->choices, $data->baseVersion)
                    : throw new \LogicException('Unexpected input.'),
                'repertoire_move_promote' => $this->editor->promote($user, $id, $move(), $data->baseVersion),
                'repertoire_move_annotate' => $data instanceof AnnotateInput
                    ? $this->editor->annotate($user, $id, $move(), $data->comment, $data->nags, $data->baseVersion)
                    : throw new \LogicException('Unexpected input.'),
                'repertoire_move_delete' => $this->editor->delete($user, $id, $move(), $data->baseVersion),
                'repertoire_undo' => $this->editor->undo($user, $id, $data->baseVersion),
                default => throw new \LogicException('Unknown repertoire change.'),
            };
        } catch (RepertoireNotFoundException) {
            throw new NotFoundHttpException('Repertoire not found.');
        } catch (MoveNotFoundException|PositionNotFoundException|TrashNotFoundException $e) {
            throw new NotFoundHttpException($e->getMessage());
        } catch (PositionOccupiedException $e) {
            throw new ConflictHttpException('position_occupied', $e);
        } catch (RestoreImpossibleException $e) {
            throw new ConflictHttpException('start_missing', $e);
        } catch (IllegalMoveException|InvalidPositionException $e) {
            throw new UnprocessableEntityHttpException('Illegal move.', $e);
        } catch (RepeatedPositionException|InvalidAnnotationException|LimitReachedException $e) {
            throw new UnprocessableEntityHttpException($e->getMessage(), $e);
        } catch (StaleVersionException|NothingToUndoException $e) {
            throw new ConflictHttpException($e->getMessage(), $e);
        }

        return $this->view($id, $change, $user);
    }

    private function view(Uuid $id, GraphChange $change, \App\Entity\User $user): Change
    {
        $repertoire = $this->repertoires->findOwned($id, $user) ?? throw new NotFoundHttpException();
        $view = new Change();
        $view->id = $id->toRfc4122();
        $view->version = $change->version;
        $view->operation = $change->operation;
        $view->moveId = $change->moveId;
        $view->transposition = $change->transposition;
        $view->trashId = $change->trashId;
        $view->positions = [] === $change->positions ? [] : $this->reader->positions($repertoire, array_map('strval', array_keys($change->positions)));
        $view->moves = [] === $change->moves ? [] : $this->reader->moves($repertoire, array_map('strval', array_keys($change->moves)));
        $segmentIds = array_values(array_unique(array_filter(array_map(static fn (array $move): ?string => $move['segmentId'], $view->moves))));
        $view->segments = [] === $segmentIds ? [] : $this->reader->segments($repertoire, $segmentIds);
        $view->deletedPositionIds = array_map('strval', array_keys($change->deletedPositions));
        $view->deletedMoveIds = array_map('strval', array_keys($change->deletedMoves));

        return $view;
    }
}
