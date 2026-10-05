<?php

declare(strict_types=1);

namespace App\Ops;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The secret of the operations routes (tick, deployment check; docs/DEPLOY_OVH.md), presented in
 * the `X-Tick-Token` header. Without a configured secret of 32 characters at least, or with a
 * wrong one, those routes answer 404 as if they did not exist.
 */
final readonly class OpsToken
{
    public const HEADER = 'X-Tick-Token';
    public const MIN_LENGTH = 32;

    public function __construct(
        #[Autowire('%env(OPS_TICK_TOKEN)%')]
        private string $token,
    ) {
    }

    public function isConfigured(): bool
    {
        return \strlen($this->token) >= self::MIN_LENGTH;
    }

    /**
     * @throws NotFoundHttpException
     */
    public function check(Request $request): void
    {
        if (!$this->isConfigured() || !hash_equals($this->token, $request->headers->get(self::HEADER, ''))) {
            throw new NotFoundHttpException();
        }
    }
}
