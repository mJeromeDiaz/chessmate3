<?php

declare(strict_types=1);

namespace App\Shop\Admin;

use App\Entity\Shop\Offer;
use App\Entity\Shop\Slide;
use App\Repository\Shop\OfferRepository;
use App\Repository\Shop\SlideRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * The admin's carousel (docs/SHOP.md): a slide shows an offer with a catchline and a professor's
 * picture, within a window, unless suspended; it can be removed.
 */
final readonly class SlideEditor
{
    public function __construct(
        private SlideRepository $slides,
        private OfferRepository $offers,
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @param array<mixed> $data
     *
     * @throws InvalidPayload
     */
    public function create(array $data): Slide
    {
        $fields = $this->read(new Payload($data));
        $slide = new Slide($fields['offer'], $fields['tag'], $fields['image'], $fields['position']);
        $slide->update($fields['offer'], $fields['tag'], $fields['image'], $fields['position'], $fields['startsAt'], $fields['endsAt'], $fields['suspended']);
        $this->entityManager->persist($slide);
        $this->entityManager->flush();

        return $slide;
    }

    /**
     * @param array<mixed> $data
     *
     * @throws InvalidPayload
     */
    public function update(Uuid $id, array $data): ?Slide
    {
        $slide = $this->slides->find($id);
        if (null === $slide) {
            return null;
        }
        $fields = $this->read(new Payload($data));
        $slide->update($fields['offer'], $fields['tag'], $fields['image'], $fields['position'], $fields['startsAt'], $fields['endsAt'], $fields['suspended']);
        $this->entityManager->flush();

        return $slide;
    }

    public function remove(Uuid $id): bool
    {
        $slide = $this->slides->find($id);
        if (null === $slide) {
            return false;
        }
        $this->entityManager->remove($slide);
        $this->entityManager->flush();

        return true;
    }

    /**
     * @return array{offer: Offer, tag: string, image: string, position: int, startsAt: \DateTimeImmutable|null, endsAt: \DateTimeImmutable|null, suspended: bool}
     *
     * @throws InvalidPayload
     */
    private function read(Payload $payload): array
    {
        $offerId = $payload->string('offerId', 36);
        $offer = null !== $offerId && Uuid::isValid($offerId) ? $this->offers->find(Uuid::fromString($offerId)) : null;
        if (null !== $offerId && null === $offer) {
            $payload->fail('offerId', 'Offre inconnue.');
        }
        $tag = $payload->string('tag', 40);
        $image = $payload->string('image', 40);
        if (null !== $image && 1 !== preg_match('/^[a-z0-9-]+$/', $image)) {
            $payload->fail('image', 'Un nom d’image de prof (ex. albert-cool).');
        }
        $position = $payload->int('position', -999, 999, false) ?? 0;
        $startsAt = $payload->instant('startsAt');
        $endsAt = $payload->instant('endsAt');
        if (null !== $startsAt && null !== $endsAt && $endsAt <= $startsAt) {
            $payload->fail('endsAt', 'La fin doit suivre le début.');
        }
        $suspended = $payload->bool('suspended');
        $payload->done();
        \assert(null !== $offer && null !== $tag && null !== $image);

        return ['offer' => $offer, 'tag' => $tag, 'image' => $image, 'position' => $position, 'startsAt' => $startsAt, 'endsAt' => $endsAt, 'suspended' => $suspended];
    }
}
