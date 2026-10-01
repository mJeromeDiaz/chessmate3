<?php

declare(strict_types=1);

namespace App\Controller\Repertoire;

use App\ApiResource\Repertoire\Repertoire as RepertoireResource;
use App\Entity\Repertoire\TrashedSuite;
use App\Entity\User;
use App\Repertoire\Exception\RepertoireNotFoundException;
use App\Repertoire\Exception\RestoreImpossibleException;
use App\Repertoire\Exception\TrashNotFoundException;
use App\Repertoire\Graph\GraphEditor;
use App\Repository\Repertoire\RepertoireRepository;
use App\Repository\Repertoire\TrashedSuiteRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Uid\Uuid;

/**
 * The trash of a repertoire (docs/REPERTOIRE.md, "Corbeille"): its suites, the preview of a
 * restoration (with the conflicts to choose), and the definitive deletion. Restoring is a change
 * of the graph: POST /api/repertoires/{id}/trash/{trashId}/restore (RepertoireChange). Another
 * user's repertoire: 404.
 */
#[Route('/api/repertoires/{id}/trash', requirements: ['id' => RepertoireResource::UUID_PATTERN])]
final class TrashController extends AbstractController
{
    public function __construct(
        private readonly RepertoireRepository $repertoires,
        private readonly TrashedSuiteRepository $trash,
        private readonly GraphEditor $editor,
    ) {
    }

    #[Route('', name: 'repertoire_trash_list', methods: ['GET'])]
    public function list(string $id, #[CurrentUser] User $user): JsonResponse
    {
        $repertoire = $this->repertoires->findOwned(Uuid::fromString($id), $user) ?? throw $this->createNotFoundException();

        return $this->json(['suites' => array_map(self::summary(...), $this->trash->findByRepertoire($repertoire))]);
    }

    /**
     * ?choices[<normalized FEN>]=restored|current: the preview with these choices.
     */
    #[Route('/{trashId}', name: 'repertoire_trash_preview', requirements: ['trashId' => RepertoireResource::UUID_PATTERN], methods: ['GET'])]
    public function preview(string $id, string $trashId, Request $request, #[CurrentUser] User $user): JsonResponse
    {
        $choices = [];
        foreach ($request->query->all('choices') as $fen => $choice) {
            if (\in_array($choice, ['restored', 'current'], true)) {
                $choices[(string) $fen] = $choice;
            }
        }
        $repertoire = $this->repertoires->findOwned(Uuid::fromString($id), $user) ?? throw $this->createNotFoundException();
        $suite = $this->trash->findInRepertoire(Uuid::fromString($trashId), $repertoire) ?? throw $this->createNotFoundException();

        try {
            [, $plan] = $this->editor->previewRestore($user, $repertoire->getId(), $trashId, $choices);
        } catch (RestoreImpossibleException) {
            return $this->json(self::summary($suite) + ['restorable' => false, 'preview' => null]);
        } catch (RepertoireNotFoundException|TrashNotFoundException) {
            throw $this->createNotFoundException();
        }

        return $this->json(self::summary($suite) + [
            'restorable' => true,
            'preview' => [
                'conflicts' => $plan->conflicts,
                'positions' => \count($plan->positions),
                'moves' => \count($plan->moves),
                'joined' => $plan->joined,
                'leftOut' => $plan->leftOut,
                'replaced' => \count($plan->replaced),
            ],
        ]);
    }

    #[Route('/{trashId}', name: 'repertoire_trash_discard', requirements: ['trashId' => RepertoireResource::UUID_PATTERN], methods: ['DELETE'])]
    public function discard(string $id, string $trashId, #[CurrentUser] User $user): Response
    {
        try {
            $this->editor->discard($user, Uuid::fromString($id), $trashId);
        } catch (RepertoireNotFoundException|TrashNotFoundException) {
            throw $this->createNotFoundException();
        }

        return new Response(null, Response::HTTP_NO_CONTENT);
    }

    /**
     * @return array{id: string, reason: string, fromFen: string, uci: string, san: string, path: list<string>, positionCount: int, moveCount: int, createdAt: string}
     */
    private static function summary(TrashedSuite $suite): array
    {
        return [
            'id' => $suite->getId()->toRfc4122(),
            'reason' => $suite->getReason()->value,
            'fromFen' => $suite->getFromFen(),
            'uci' => $suite->getUci(),
            'san' => $suite->getSan(),
            'path' => $suite->getPath(),
            'positionCount' => $suite->getPositionCount(),
            'moveCount' => $suite->getMoveCount(),
            'createdAt' => $suite->getCreatedAt()->format(\DATE_ATOM),
        ];
    }
}
