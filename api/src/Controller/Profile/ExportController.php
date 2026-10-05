<?php

declare(strict_types=1);

namespace App\Controller\Profile;

use App\Entity\User;
use App\Enum\AuditEventType;
use App\Security\Account\DataExport;
use App\Security\Audit\AuditLogger;
use App\Security\RateLimit\RateLimitGuard;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * GET /api/profile/export: every data of the signed-in user as a ZIP (JSON + PGN, docs/AUTH.md),
 * built on the request and sent at once; the temporary file is deleted after sending. Allowed to a
 * frozen account (deletion scheduled). 3 exports a day.
 */
final class ExportController extends AbstractController
{
    public function __construct(
        private readonly DataExport $export,
        private readonly RateLimitGuard $rateLimitGuard,
        private readonly AuditLogger $auditLogger,
        #[Autowire(service: 'limiter.profile_export')]
        private readonly RateLimiterFactory $exportLimiter,
    ) {
    }

    #[Route('/api/profile/export', name: 'app_profile_export', methods: ['GET'])]
    public function __invoke(#[CurrentUser] User $user): BinaryFileResponse
    {
        $this->rateLimitGuard->consume($this->exportLimiter, $user->getUserIdentifier());
        $path = $this->export->build($user);
        $this->auditLogger->log(AuditEventType::DataExported, $user);

        $response = new BinaryFileResponse($path, headers: [
            'Content-Type' => 'application/zip',
            'Cache-Control' => 'private, no-store',
        ]);
        $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, $this->export->fileName());

        return $response->deleteFileAfterSend();
    }
}
