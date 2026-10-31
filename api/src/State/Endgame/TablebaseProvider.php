<?php

declare(strict_types=1);

namespace App\State\Endgame;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Endgame\Tablebase as TablebaseView;
use App\Chess\InvalidPositionException;
use App\Chess\Position\FenNormalizer;
use App\Endgame\Tablebase\BestDefence;
use App\Endgame\Tablebase\MoveAnswer;
use App\Endgame\Tablebase\Tablebase;
use App\Guest\GuestPolicy;
use App\Repertoire\Lichess\LichessUnavailableException;
use App\Repository\Training\RunRepository;
use App\Security\AuthenticatedUser;
use App\Security\RateLimit\RateLimitGuard;
use App\State\Repertoire\LichessUnavailableHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * GET /endgames/tablebase?fen=.
 *
 * @implements ProviderInterface<TablebaseView>
 */
final readonly class TablebaseProvider implements ProviderInterface
{
    public function __construct(
        private Tablebase $tablebase,
        private FenNormalizer $normalizer,
        private AuthenticatedUser $authenticatedUser,
        private RateLimitGuard $rateLimitGuard,
        private RateLimiterFactoryInterface $endgameTablebaseLimiter,
        private GuestPolicy $guests,
        private RunRepository $runs,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): TablebaseView
    {
        $user = $this->authenticatedUser->get();
        $this->rateLimitGuard->consume($this->endgameTablebaseLimiter, $user->getId()->toRfc4122());
        $this->guests->assertTablebase($user, $user->isGuest() ? $this->runs->findActiveOf($user) : null);
        $filters = \is_array($context['filters'] ?? null) ? $context['filters'] : [];

        try {
            $fen = $this->normalizer->normalize(\is_string($filters['fen'] ?? null) ? $filters['fen'] : '');
            $answer = $this->tablebase->probe($fen);
        } catch (InvalidPositionException $e) {
            throw new UnprocessableEntityHttpException($e->getMessage(), $e);
        } catch (LichessUnavailableException $e) {
            throw LichessUnavailableHttpException::from($e);
        }

        $view = new TablebaseView();
        $view->fen = $fen;
        if (null === $answer) {
            return $view;
        }
        $view->known = true;
        $view->result = $answer->result()?->value;
        $view->dtm = $answer->dtm;
        $view->dtz = $answer->dtz;
        $view->checkmate = $answer->checkmate;
        $view->stalemate = $answer->stalemate;
        $view->insufficientMaterial = $answer->insufficientMaterial;
        $view->moves = array_map(static fn (MoveAnswer $move): array => [
            'uci' => $move->uci,
            'result' => $move->resultForMover()?->value,
            'dtm' => $move->dtm,
            'dtz' => $move->dtz,
            'zeroing' => $move->zeroing,
        ], $answer->moves);
        $view->defence = BestDefence::moves($answer);

        return $view;
    }
}
