<?php

declare(strict_types=1);

namespace App\State\Repertoire;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\Repertoire\ApplyImportInput;
use App\ApiResource\Repertoire\Import;
use App\Enum\Repertoire\Color;
use App\Repertoire\Exception\LimitReachedException;
use App\Repertoire\Exception\RepertoireNotFoundException;
use App\Repertoire\Exception\StaleVersionException;
use App\Repertoire\Import\ImportNotReadyException;
use App\Repertoire\Import\ImportService;
use App\Security\AuthenticatedUser;
use App\Security\RateLimit\RateLimitGuard;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Uid\Uuid;

/**
 * POST /repertoires/imports/{id}/apply: "done" with the repertoire when applied at once, or
 * "applying" when a worker does it (poll the import). 404 unknown import or repertoire; 409 not
 * ready, or the repertoire changed since the preview (baseVersion); 422 a limit or an invalid name.
 *
 * @implements ProcessorInterface<ApplyImportInput, Import>
 */
final class ApplyImportProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly ImportService $imports,
        private readonly AuthenticatedUser $authenticatedUser,
        private readonly RateLimitGuard $rateLimitGuard,
        private readonly RateLimiterFactory $repertoireEditLimiter,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): Import
    {
        $user = $this->authenticatedUser->get();
        $this->rateLimitGuard->consume($this->repertoireEditLimiter, $user->getId()->toRfc4122());
        $id = RepertoireIds::fromUri($uriVariables, 'id');
        $import = null === $id ? null : $this->imports->get($user, $id);
        if (null === $import) {
            throw new NotFoundHttpException();
        }
        $target = null !== $data->repertoireId
            ? ['repertoireId' => Uuid::fromString($data->repertoireId)]
            : ['name' => (string) $data->name, 'color' => Color::from((string) $data->color)];

        try {
            return Import::from($this->imports->apply($import, $target, $data->choices, $data->baseVersion));
        } catch (RepertoireNotFoundException) {
            throw new NotFoundHttpException('Repertoire not found.');
        } catch (ImportNotReadyException|StaleVersionException $e) {
            throw new ConflictHttpException($e->getMessage(), $e);
        } catch (LimitReachedException $e) {
            throw new UnprocessableEntityHttpException('limit_'.$e->limit, $e);
        } catch (\InvalidArgumentException $e) {
            throw new UnprocessableEntityHttpException('invalid_name', $e);
        }
    }
}
