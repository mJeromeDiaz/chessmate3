<?php

declare(strict_types=1);

namespace App\State\Repertoire;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\Repertoire\CreateImportInput;
use App\ApiResource\Repertoire\Import;
use App\Entity\Repertoire\Import as ImportEntity;
use App\Repertoire\Import\ImportRejectedException;
use App\Repertoire\Import\ImportService;
use App\Repertoire\Lichess\LichessUnavailableException;
use App\Repertoire\Lichess\StudyClient;
use App\Repertoire\Lichess\StudyUnavailableException;
use App\Security\AuthenticatedUser;
use App\Security\RateLimit\RateLimitGuard;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactory;

/**
 * POST /repertoires/imports: 201 with the import, analysed at once when small (or failed: its
 * error says why), else "analyzing" (poll it). 422 (detail: the reason) when the text is over the
 * size limit, the study URL is invalid, or Lichess did not give the study (study_private: grant
 * study:read); 503 when Lichess is unavailable.
 *
 * @implements ProcessorInterface<CreateImportInput, Import>
 */
final class CreateImportProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly ImportService $imports,
        private readonly StudyClient $studies,
        private readonly AuthenticatedUser $authenticatedUser,
        private readonly RateLimitGuard $rateLimitGuard,
        private readonly RateLimiterFactory $repertoireImportLimiter,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): Import
    {
        $user = $this->authenticatedUser->get();
        $this->rateLimitGuard->consume($this->repertoireImportLimiter, $user->getId()->toRfc4122());

        try {
            if (null !== $data->studyUrl && '' !== trim($data->studyUrl)) {
                [$studyId, $chapterId] = StudyClient::parse($data->studyUrl) ?? throw new UnprocessableEntityHttpException('invalid_study_url');

                return Import::from($this->imports->create($user, ImportEntity::SOURCE_STUDY, trim($data->studyUrl), $this->studies->pgn($user, $studyId, $chapterId)));
            }

            return Import::from($this->imports->create($user, ImportEntity::SOURCE_PGN, $data->fileName, (string) $data->pgn));
        } catch (ImportRejectedException $e) {
            throw new UnprocessableEntityHttpException($e->reason, $e);
        } catch (StudyUnavailableException $e) {
            throw new UnprocessableEntityHttpException($e->reason, $e);
        } catch (LichessUnavailableException $e) {
            throw LichessUnavailableHttpException::from($e);
        }
    }
}
