<?php

declare(strict_types=1);

namespace App\Controller\Ops;

use App\Ops\OpsToken;
use App\Ops\Tick\TickRunner;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * POST /api/ops/tick, called every minute by an external scheduler (docs/DEPLOY_OVH.md) with the
 * secret in the `X-Tick-Token` header ({@see OpsToken}: 404 without it).
 */
final class TickController extends AbstractController
{
    public function __construct(
        private readonly TickRunner $runner,
        private readonly OpsToken $token,
    ) {
    }

    #[Route('/api/ops/tick', name: 'app_ops_tick', methods: ['POST'])]
    public function __invoke(Request $request): JsonResponse
    {
        $this->token->check($request);
        $started = microtime(true);
        $result = $this->runner->run();

        $response = $this->json($result + ['durationMs' => (int) round((microtime(true) - $started) * 1000)]);
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }
}
