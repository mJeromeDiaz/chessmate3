<?php

declare(strict_types=1);

namespace App\State\Training;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\Training\Run;
use App\Security\AuthenticatedUser;
use App\Training\Exception\RunNotFoundException;
use App\Training\Run\TimeboxRunner;
use Psr\Clock\ClockInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * POST /training/runs/{id}/stop.
 *
 * @implements ProcessorInterface<mixed, Run>
 */
final class StopRunProcessor implements ProcessorInterface
{
    use RunIdTrait;

    public function __construct(
        private readonly TimeboxRunner $runner,
        private readonly AuthenticatedUser $authenticatedUser,
        private readonly ClockInterface $clock,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): Run
    {
        try {
            return Run::from($this->runner->stop($this->authenticatedUser->get(), self::runId($uriVariables)), $this->clock->now());
        } catch (RunNotFoundException) {
            throw new NotFoundHttpException('Run not found.');
        }
    }
}
