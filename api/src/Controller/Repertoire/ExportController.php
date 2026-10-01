<?php

declare(strict_types=1);

namespace App\Controller\Repertoire;

use App\ApiResource\Repertoire\Repertoire as RepertoireResource;
use App\Entity\User;
use App\Repertoire\OpenBook\Exporter as OpenBookExporter;
use App\Repertoire\Pgn\Exporter;
use App\Repository\Repertoire\RepertoireRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Uid\Uuid;

/**
 * GET /api/repertoires/{id}/export[?format=openbook]: the repertoire as a PGN file, or as an
 * OpenBook backup (JSON). The user keeps control of their data. A plain controller: the answer is
 * a file, not a resource. Another user's repertoire, or an unknown format: 404.
 */
final class ExportController extends AbstractController
{
    public function __construct(
        private readonly RepertoireRepository $repertoires,
        private readonly Exporter $exporter,
        private readonly OpenBookExporter $openBook,
    ) {
    }

    #[Route('/api/repertoires/{id}/export', name: 'repertoire_export', requirements: ['id' => RepertoireResource::UUID_PATTERN], methods: ['GET'])]
    public function __invoke(string $id, Request $request, #[CurrentUser] User $user): Response
    {
        $repertoire = $this->repertoires->findOwned(Uuid::fromString($id), $user) ?? throw $this->createNotFoundException();
        $format = $request->query->getString('format', 'pgn');
        if ('openbook' === $format) {
            return new Response($this->openBook->export($repertoire), Response::HTTP_OK, [
                'Content-Type' => 'application/json; charset=UTF-8',
                'Content-Disposition' => HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, OpenBookExporter::fileName($repertoire)),
                'Cache-Control' => 'private, no-store',
            ]);
        }
        if ('pgn' !== $format) {
            throw $this->createNotFoundException();
        }

        return new Response($this->exporter->export($repertoire), Response::HTTP_OK, [
            'Content-Type' => 'application/x-chess-pgn; charset=UTF-8',
            'Content-Disposition' => HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, Exporter::fileName($repertoire)),
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
