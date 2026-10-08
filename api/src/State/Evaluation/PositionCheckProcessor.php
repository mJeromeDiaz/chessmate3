<?php

declare(strict_types=1);

namespace App\State\Evaluation;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\Evaluation\PositionCheck;
use App\ApiResource\Evaluation\PositionCheckInput;
use App\Evaluation\EvaluationRules;
use App\Evaluation\Position\PositionEditor;
use App\Evaluation\Position\PositionRefusedException;
use App\Evaluation\Verification\PositionVerifier;
use App\Security\AuthenticatedUser;
use App\Security\RateLimit\RateLimitGuard;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactory;

/**
 * POST /admin/evaluation/verification, within the admins' write budget (each check reaches
 * Lichess, one request at a time): 422 for an illegal FEN.
 *
 * @implements ProcessorInterface<PositionCheckInput, PositionCheck>
 */
final class PositionCheckProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly PositionVerifier $verifier,
        private readonly AuthenticatedUser $authenticatedUser,
        private readonly RateLimitGuard $rateLimitGuard,
        private readonly RateLimiterFactory $adminWriteLimiter,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): PositionCheck
    {
        $this->rateLimitGuard->consume($this->adminWriteLimiter, $this->authenticatedUser->get()->getId()->toRfc4122());
        try {
            [$fen, $turn] = PositionEditor::normalize($data->fen);
        } catch (PositionRefusedException $e) {
            throw new UnprocessableEntityHttpException($e->getMessage());
        }
        $check = $this->verifier->verify($fen, $turn, $data->evalCp);

        $view = new PositionCheck();
        $view->fen = $fen;
        $view->turn = $turn->value;
        $view->category = EvaluationRules::category($data->evalCp);
        $view->nearBorder = EvaluationRules::nearBorder($data->evalCp);
        $view->verdict = $check['verdict'];
        $view->source = $check['source'];
        $view->lichess = $check['lichess'];
        $view->lichessCategory = $check['lichessCategory'];

        return $view;
    }
}
