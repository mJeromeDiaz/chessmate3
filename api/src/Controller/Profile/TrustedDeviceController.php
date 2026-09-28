<?php

declare(strict_types=1);

namespace App\Controller\Profile;

use App\Entity\TrustedDevice;
use App\Entity\User;
use App\Security\TrustedDevice\TrustedDeviceService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Uid\Uuid;

/**
 * Trusted devices are managed from the profile page, but authentication (not API Platform, see
 * docs/AUTH.md) keeps this a plain controller like the rest of the auth domain, for the same
 * tight-control-over-serialization reason.
 */
#[Route('/api/profile/trusted-devices')]
final class TrustedDeviceController extends AbstractController
{
    public function __construct(private readonly TrustedDeviceService $trustedDeviceService)
    {
    }

    #[Route('', name: 'app_profile_trusted_devices_list', methods: ['GET'])]
    public function list(#[CurrentUser] User $user): JsonResponse
    {
        $devices = array_map(
            static fn (TrustedDevice $device): array => [
                'id' => $device->getId()->toRfc4122(),
                'label' => $device->getLabel(),
                'createdAt' => $device->getCreatedAt()->format(\DATE_ATOM),
                'lastUsedAt' => $device->getLastUsedAt()?->format(\DATE_ATOM),
                'expiresAt' => $device->getExpiresAt()->format(\DATE_ATOM),
            ],
            $this->trustedDeviceService->listActiveForUser($user),
        );

        return $this->json(['devices' => $devices]);
    }

    #[Route('/{id}', name: 'app_profile_trusted_devices_revoke', methods: ['DELETE'])]
    public function revoke(string $id, #[CurrentUser] User $user): JsonResponse
    {
        if (!Uuid::isValid($id)) {
            throw $this->createNotFoundException();
        }

        try {
            $this->trustedDeviceService->revoke($user, Uuid::fromString($id));
        } catch (\DomainException) {
            throw $this->createNotFoundException();
        }

        return $this->json(['message' => 'Device revoked.'], Response::HTTP_OK);
    }
}
