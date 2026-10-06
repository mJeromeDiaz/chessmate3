<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Puzzle\Selection\SelectionUnavailableException;
use App\State\Puzzle\PuzzleMaintenanceHttpException;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * A themed draw refused during a rebuild of the selection index can surface from several
 * endpoints (next puzzle, timed runs, Woodpecker sets): it answers 503 + `X-Puzzle-Maintenance`
 * from any of them. Priority 10: before the error listener logs and renders the exception (0, -128).
 */
#[AsEventListener(event: KernelEvents::EXCEPTION, method: 'onKernelException', priority: 10)]
final class PuzzleMaintenanceListener
{
    public function onKernelException(ExceptionEvent $event): void
    {
        $throwable = $event->getThrowable();
        while (null !== $throwable && !$throwable instanceof SelectionUnavailableException) {
            $throwable = $throwable->getPrevious();
        }

        if (null !== $throwable) {
            $event->setThrowable(PuzzleMaintenanceHttpException::from($throwable));
        }
    }
}
