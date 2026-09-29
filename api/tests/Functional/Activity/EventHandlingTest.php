<?php

declare(strict_types=1);

namespace App\Tests\Functional\Activity;

use App\Activity\Event\DomainEventInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Handler\HandlersLocatorInterface;

/**
 * Every domain event has a handler: without one, the worker would retry it and move it to the
 * "failed" transport (NoHandlerForMessageException).
 */
final class EventHandlingTest extends KernelTestCase
{
    public function testEveryDomainEventHasAHandler(): void
    {
        self::bootKernel();
        /** @var HandlersLocatorInterface $locator */
        $locator = self::getContainer()->get('messenger.bus.default.messenger.handlers_locator');

        $events = self::domainEvents();
        self::assertGreaterThanOrEqual(6, \count($events));
        foreach ($events as $class) {
            $event = (new \ReflectionClass($class))->newInstanceWithoutConstructor();
            self::assertNotEmpty([...$locator->getHandlers(new Envelope($event))], $class.' has no handler.');
        }
    }

    /**
     * @return list<class-string<DomainEventInterface>>
     */
    private static function domainEvents(): array
    {
        $events = [];
        foreach ((new Finder())->files()->in(\dirname(__DIR__, 3).'/src')->name('*.php') as $file) {
            $class = 'App\\'.str_replace(['/', '.php'], ['\\', ''], $file->getRelativePathname());
            if (class_exists($class) && is_subclass_of($class, DomainEventInterface::class) && !(new \ReflectionClass($class))->isAbstract()) {
                $events[] = $class;
            }
        }

        return $events;
    }
}
