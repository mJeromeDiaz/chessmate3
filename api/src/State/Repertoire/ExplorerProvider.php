<?php

declare(strict_types=1);

namespace App\State\Repertoire;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Repertoire\Explorer;
use App\Chess\InvalidPositionException;
use App\Chess\Position\FenNormalizer;
use App\Repertoire\Lichess\ExplorerClient;
use App\Repertoire\Lichess\ExplorerQuery;
use App\Repertoire\Lichess\LichessUnavailableException;
use App\Security\AuthenticatedUser;
use App\Security\RateLimit\RateLimitGuard;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactory;

/**
 * GET /repertoires/explorer/{masters|lichess}?fen=&speeds=&ratings=.
 *
 * @implements ProviderInterface<Explorer>
 */
final class ExplorerProvider implements ProviderInterface
{
    public function __construct(
        private readonly ExplorerClient $explorer,
        private readonly FenNormalizer $normalizer,
        private readonly AuthenticatedUser $authenticatedUser,
        private readonly RateLimitGuard $rateLimitGuard,
        private readonly RateLimiterFactory $repertoireExplorerLimiter,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): Explorer
    {
        $user = $this->authenticatedUser->get();
        $this->rateLimitGuard->consume($this->repertoireExplorerLimiter, $user->getId()->toRfc4122());
        $filters = \is_array($context['filters'] ?? null) ? $context['filters'] : [];
        $source = \is_string($uriVariables['source'] ?? null) ? $uriVariables['source'] : '';

        try {
            $fen = $this->normalizer->normalize(\is_string($filters['fen'] ?? null) ? $filters['fen'] : '');
            $query = new ExplorerQuery(
                $source,
                $fen,
                self::list($filters['speeds'] ?? null),
                array_map('intval', self::list($filters['ratings'] ?? null)),
            );
        } catch (InvalidPositionException|\InvalidArgumentException $e) {
            throw new UnprocessableEntityHttpException($e->getMessage(), $e);
        }

        try {
            $answer = $this->explorer->explore($user, $query);
        } catch (LichessUnavailableException $e) {
            throw LichessUnavailableHttpException::from($e);
        }

        $view = new Explorer();
        $view->source = $source;
        $view->fen = $fen;
        $view->white = $answer['white'];
        $view->draws = $answer['draws'];
        $view->black = $answer['black'];
        $view->total = $answer['total'];
        $view->moves = $answer['moves'];
        $view->opening = $answer['opening'];

        return $view;
    }

    /**
     * @return list<string>
     */
    private static function list(mixed $value): array
    {
        return \is_string($value) && '' !== $value ? array_values(array_filter(explode(',', $value), static fn (string $part): bool => '' !== $part)) : [];
    }
}
