<?php

declare(strict_types=1);

namespace App\Tests\SharedKernel\Infrastructure\Doctrine;

use App\Client\Domain\Client\Client;
use App\Client\Domain\Client\Factory\ClientFactory;
use App\Client\Domain\Client\Outside\ClientOutsideInterface;
use App\SharedKernel\Domain\Attribute\OutsideField;
use App\SharedKernel\Domain\ValueObject\DateTime;
use App\SharedKernel\Domain\ValueObject\Id;
use App\SharedKernel\Infrastructure\Doctrine\EventSubscriber\OutsidePostLoadSubscriber;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\PostLoadEventArgs;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ServiceLocator;

final class OutsidePostLoadSubscriberTest extends TestCase
{
    public function testItAttachesOutsideUsingMarkerAndConventionAndIsIdempotent(): void
    {
        $outside1 = $this->createStub(ClientOutsideInterface::class);
        $outside2 = $this->createStub(ClientOutsideInterface::class);
        $outside1->method('now')->willReturn(DateTime::now());
        $outside2->method('now')->willReturn(DateTime::now());

        $subscriber1 = new OutsidePostLoadSubscriber($this->locatorFor(ClientOutsideInterface::class, $outside1));
        $subscriber2 = new OutsidePostLoadSubscriber($this->locatorFor(ClientOutsideInterface::class, $outside2));

        $client = $this->newClientDomainObject($outside1);
        $this->setOutsideViaMarker($client, null);

        // 1) postLoad attaches outside
        $subscriber1->postLoad($this->postLoadArgs($client));
        self::assertSame($outside1, $this->readOutsideViaMarker($client));

        // 2) idempotent: second attach does not overwrite
        $subscriber2->postLoad($this->postLoadArgs($client));
        self::assertSame($outside1, $this->readOutsideViaMarker($client));
    }

    private function newClientDomainObject(ClientOutsideInterface $outside): Client
    {
        $factory = new ClientFactory($outside);

        return $factory->create(Id::new(), 'ACME Corp', null);
    }

    /**
     * @phpstan-return ServiceLocator<object>
     */
    private function locatorFor(string $id, object $service): ServiceLocator
    {
        /** @var ServiceLocator<object> $locator */
        $locator = new ServiceLocator([$id => static fn (): object => $service]);

        return $locator;
    }

    private function postLoadArgs(object $entity): PostLoadEventArgs
    {
        $em = $this->createStub(EntityManagerInterface::class);

        return new PostLoadEventArgs($entity, $em);
    }

    private function readOutsideViaMarker(object $entity): ?object
    {
        $ref = new \ReflectionObject($entity);

        foreach ($ref->getProperties() as $property) {
            if ([] !== $property->getAttributes(OutsideField::class)) {
                $property->setAccessible(true);
                $value = $property->getValue($entity);

                return \is_object($value) ? $value : null;
            }
        }

        self::fail('No property marked with #[OutsideField] found.');
    }

    private function setOutsideViaMarker(object $entity, ?object $value): void
    {
        $ref = new \ReflectionObject($entity);

        foreach ($ref->getProperties() as $property) {
            if ([] !== $property->getAttributes(OutsideField::class)) {
                $property->setAccessible(true);
                $property->setValue($entity, $value);

                return;
            }
        }

        self::fail('No property marked with #[OutsideField] found.');
    }
}
