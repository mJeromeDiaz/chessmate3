<?php

declare(strict_types=1);

namespace App\Controller\Ops;

use App\Ops\Check\DeploymentChecker;
use App\Ops\OpsToken;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * GET /api/ops/check[?network=1] (secret in `X-Tick-Token`, 404 without it): the deployment checks
 * as the web's PHP sees them, plus the client IP the API resolves (the per-IP rate limits rely on
 * it), and the scheme and host it sees (the links of the emails are built from them): behind a proxy
 * or CDN, set SYMFONY_TRUSTED_PROXIES. docs/DEPLOY_OVH.md.
 */
final class CheckController extends AbstractController
{
    public function __construct(
        private readonly DeploymentChecker $checker,
        private readonly OpsToken $token,
    ) {
    }

    #[Route('/api/ops/check', name: 'app_ops_check', methods: ['GET'])]
    public function __invoke(Request $request): JsonResponse
    {
        $this->token->check($request);
        $checks = $this->checker->run($request->query->getBoolean('network'));

        $response = $this->json([
            'ok' => !DeploymentChecker::hasErrors($checks),
            'checks' => $checks,
            'clientIp' => $request->getClientIp(),
            'remoteAddr' => $request->server->get('REMOTE_ADDR'),
            'forwardedFor' => $request->headers->get('X-Forwarded-For'),
            // The links of the emails (address verification) are built from it: must be https.
            'scheme' => $request->getScheme(),
            'host' => $request->getHost(),
        ]);
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }
}
