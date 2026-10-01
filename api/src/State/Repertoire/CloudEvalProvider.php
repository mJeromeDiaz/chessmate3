<?php

declare(strict_types=1);

namespace App\State\Repertoire;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Repertoire\CloudEval;
use App\Chess\InvalidPositionException;
use App\Chess\Position\FenNormalizer;
use App\Repertoire\Lichess\CloudEvalClient;
use App\Repertoire\Lichess\LichessUnavailableException;
use App\Security\AuthenticatedUser;
use App\Security\RateLimit\RateLimitGuard;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactory;

/**
 * GET /repertoires/cloud-eval?fen=&lines=.
 *
 * @implements ProviderInterface<CloudEval>
 */
final class CloudEvalProvider implements ProviderInterface
{
    public function __construct(
        private readonly CloudEvalClient $cloudEval,
        private readonly FenNormalizer $normalizer,
        private readonly AuthenticatedUser $authenticatedUser,
        private readonly RateLimitGuard $rateLimitGuard,
        private readonly RateLimiterFactory $repertoireExplorerLimiter,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): CloudEval
    {
        $user = $this->authenticatedUser->get();
        $this->rateLimitGuard->consume($this->repertoireExplorerLimiter, $user->getId()->toRfc4122());
        $filters = \is_array($context['filters'] ?? null) ? $context['filters'] : [];

        try {
            $fen = $this->normalizer->normalize(\is_string($filters['fen'] ?? null) ? $filters['fen'] : '');
        } catch (InvalidPositionException $e) {
            throw new UnprocessableEntityHttpException($e->getMessage(), $e);
        }
        $lines = is_numeric($filters['lines'] ?? null) ? (int) $filters['lines'] : 3;

        try {
            $answer = $this->cloudEval->evaluate($fen, $lines);
        } catch (LichessUnavailableException $e) {
            throw LichessUnavailableHttpException::from($e);
        }

        $view = new CloudEval();
        $view->fen = $fen;
        $view->found = $answer['found'];
        $view->depth = $answer['depth'];
        $view->knodes = $answer['knodes'];
        $view->lines = $answer['lines'];

        return $view;
    }
}
