<?php

declare(strict_types=1);

namespace App\Tests\Client\Application\Client;

use App\Client\Application\Client\Command\OnboardClient\OnboardClientCommand;
use App\SharedKernel\Application\CommandBus\CommandBusInterface;
use App\SharedKernel\Application\IntegrationEvent\IntegrationEvent;
use App\SharedKernel\Application\IntegrationEvent\IntegrationEventPublisherInterface;
use App\SharedKernel\Domain\Clock\ClockInterface;
use App\SharedKernel\Domain\ValueObject\Email;
use App\SharedKernel\Domain\ValueObject\Id;
use App\SharedKernel\Infrastructure\IntegrationEvent\DbalOutboxPublisher;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

final class OnboardClientIntegrationTest extends KernelTestCase
{
    public function testFailureAfterOutboxWriteRollsBackEntireOnboarding(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $connection = $container->get(Connection::class);
        $normalizer = $container->get('serializer');
        $clock = $container->get(ClockInterface::class);
        self::assertInstanceOf(Connection::class, $connection);
        self::assertInstanceOf(NormalizerInterface::class, $normalizer);
        self::assertInstanceOf(ClockInterface::class, $clock);
        // Do not initialize the container publisher before replacing its alias below.
        $publisher = new DbalOutboxPublisher($connection, $normalizer, $clock);
        $id = Id::new();
        $email = Email::fromString((string) Id::new() . '@example.com');
        $failure = new \RuntimeException('Simulated failure after durable notification enqueue');
        $failingPublisher = $this->createStub(IntegrationEventPublisherInterface::class);
        $failingPublisher->method('publish')->willReturnCallback(
            static function (IntegrationEvent $event) use ($publisher, $connection, $id, $failure): void {
                $publisher->publish($event);
                // Failure happens after real ORM flush and real outbox insertion, before commit.
                self::assertNotFalse($connection->fetchOne('SELECT id FROM client.clients WHERE id = :id', ['id' => (string) $id]));
                self::assertNotFalse($connection->fetchOne('SELECT id FROM client.client_invitations WHERE client_id = :id', ['id' => (string) $id]));
                self::assertNotFalse($connection->fetchOne("SELECT event_id FROM shared.async_outbox WHERE payload ->> 'clientId' = :id", ['id' => (string) $id]));
                throw $failure;
            },
        );
        $container->set(IntegrationEventPublisherInterface::class, $failingPublisher);
        $bus = $container->get(CommandBusInterface::class);
        self::assertInstanceOf(CommandBusInterface::class, $bus);
        try {
            $bus->dispatch(new OnboardClientCommand($id, 'Atomic onboarding', null, $email));
            self::fail('Onboarding must fail.');
        } catch (\RuntimeException $exception) {
            self::assertSame($failure, $exception);
        }

        self::assertFalse($connection->isTransactionActive());
        self::assertSame([], $connection->fetchFirstColumn('SELECT id FROM client.clients WHERE id = :id', ['id' => (string) $id]));
        self::assertSame([], $connection->fetchFirstColumn('SELECT id FROM client.client_invitations WHERE client_id = :id', ['id' => (string) $id]));
        self::assertSame([], $connection->fetchFirstColumn("SELECT event_id FROM shared.async_outbox WHERE payload ->> 'clientId' = :id", ['id' => (string) $id]));
        self::assertSame([], $connection->fetchFirstColumn("SELECT event_id FROM shared.event_log WHERE payload ->> 'clientId' = :id", ['id' => (string) $id]));
    }
}
