<?php

declare(strict_types=1);

namespace App\Tests\User\Application\User;

use App\SharedKernel\Application\CommandBus\CommandBusInterface;
use App\User\Application\IntegrationEvent\UserRegisteredIntegrationEvent;
use App\User\Application\User\Command\UpsertUserByEmail\UpsertUserByEmailCommand;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class UserRegisteredAsyncNotificationTest extends KernelTestCase
{
    private Connection $connection;
    private CommandBusInterface $commandBus;

    protected function setUp(): void
    {
        self::bootKernel();

        $container = static::getContainer();

        /** @var \Symfony\Bundle\FrameworkBundle\Test\TestContainer $testContainer */
        $testContainer = $container->get('test.service_container');

        $connection = $testContainer->get(Connection::class);
        $commandBus = $testContainer->get(CommandBusInterface::class);

        $this->assertInstanceOf(Connection::class, $connection);
        $this->assertInstanceOf(CommandBusInterface::class, $commandBus);

        $this->connection = $connection;
        $this->commandBus = $commandBus;
        $this->connection->executeStatement('DELETE FROM shared.async_consumption');
        $this->connection->executeStatement('DELETE FROM shared.async_outbox');
        $this->connection->executeStatement('DELETE FROM "user".otp_challenges');
        $this->connection->executeStatement('DELETE FROM "user".users');
        $this->connection->executeStatement('DELETE FROM shared.event_log');
    }

    public function testRegistrationStoresIntegrationEventInOutbox(): void
    {
        $this->registerUser('outbox-user@example.com');

        $row = $this->fetchOutboxRow('outbox-user@example.com');

        $this->assertSame(UserRegisteredIntegrationEvent::class, $row['event_name']);
        $this->assertNull($row['processed_at']);
        $this->assertSame(0, $this->intValue($row['attempts']));
    }

    public function testWorkerStoresProcessedConsumptionForSubscriber(): void
    {
        $this->registerUser('consumption-user@example.com');

        $this->executeWorker();

        $this->assertSame(1, $this->processedConsumptionCount());
    }

    private function registerUser(string $email): void
    {
        $this->commandBus->dispatch(new UpsertUserByEmailCommand($email));
    }

    private function executeWorker(): void
    {
        $kernel = self::$kernel;
        $this->assertNotNull($kernel);

        $application = new Application($kernel);
        $tester = new CommandTester($application->find('app:process-outbox'));

        $exitCode = $tester->execute(['--once' => true]);

        $this->assertSame(0, $exitCode, $tester->getDisplay());
    }

    /**
     * @return array<string, mixed>
     */
    private function fetchOutboxRow(string $email): array
    {
        $row = $this->connection->fetchAssociative(
            "SELECT event_name, payload, attempts, processed_at FROM shared.async_outbox WHERE event_name = :event_name AND payload ->> 'email' = :email ORDER BY id DESC LIMIT 1",
            [
                'event_name' => UserRegisteredIntegrationEvent::class,
                'email' => $email,
            ],
        );

        $this->assertIsArray($row);

        return $row;
    }

    private function processedConsumptionCount(): int
    {
        return $this->intValue($this->connection->fetchOne(
            'SELECT COUNT(*) FROM shared.async_consumption WHERE subscriber = :subscriber AND handler_method = :handler_method AND status = :status',
            [
                'subscriber' => \App\User\Application\IntegrationEventSubscriber\SendUserRegisteredNotificationSubscriber::class,
                'handler_method' => 'onUserRegistered',
                'status' => 'processed',
            ],
        ));
    }

    private function intValue(mixed $value): int
    {
        if (!\is_int($value) && !\is_string($value)) {
            throw new \RuntimeException('Expected an integer-compatible value.');
        }

        return (int) $value;
    }
}
