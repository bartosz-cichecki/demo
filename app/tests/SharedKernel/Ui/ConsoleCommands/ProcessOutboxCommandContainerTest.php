<?php

declare(strict_types=1);

namespace App\Tests\SharedKernel\Ui\ConsoleCommands;

use App\SharedKernel\Application\IntegrationEvent\IntegrationEvent;
use App\SharedKernel\Application\IntegrationEvent\IntegrationEventPublisherInterface;
use App\SharedKernel\Domain\Clock\MutableClock;
use App\Tests\SharedKernel\Ui\ConsoleCommands\Fixtures\FailingOnceIntegrationEventSubscriber;
use App\Tests\SharedKernel\Ui\ConsoleCommands\Fixtures\NeutralConsumptionOwnershipEvent;
use App\Tests\SharedKernel\Ui\ConsoleCommands\Fixtures\NeutralFailingEvent;
use App\Tests\SharedKernel\Ui\ConsoleCommands\Fixtures\NeutralHandledEvent;
use App\Tests\SharedKernel\Ui\ConsoleCommands\Fixtures\NeutralNoHandlerEvent;
use App\Tests\SharedKernel\Ui\ConsoleCommands\Fixtures\NeutralOutboxOwnershipEvent;
use App\Tests\SharedKernel\Ui\ConsoleCommands\Fixtures\RecordingIntegrationEventSubscriber;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class ProcessOutboxCommandContainerTest extends KernelTestCase
{
    private Connection $connection;
    private IntegrationEventPublisherInterface $publisher;
    private MutableClock $clock;

    protected function setUp(): void
    {
        self::bootKernel();

        $container = static::getContainer();

        /** @var \Symfony\Bundle\FrameworkBundle\Test\TestContainer $testContainer */
        $testContainer = $container->get('test.service_container');

        $connection = $testContainer->get(Connection::class);
        $publisher = $testContainer->get(IntegrationEventPublisherInterface::class);
        $clock = $testContainer->get(MutableClock::class);

        $this->assertInstanceOf(Connection::class, $connection);
        $this->assertInstanceOf(IntegrationEventPublisherInterface::class, $publisher);
        $this->assertInstanceOf(MutableClock::class, $clock);

        $this->connection = $connection;
        $this->publisher = $publisher;
        $this->clock = $clock;
        $this->clock->set(new \DateTimeImmutable('2026-04-28 12:00:00', new \DateTimeZone('UTC')));

        RecordingIntegrationEventSubscriber::reset();
        FailingOnceIntegrationEventSubscriber::reset();

        $this->connection->executeStatement('DELETE FROM shared.async_consumption');
        $this->connection->executeStatement('DELETE FROM shared.async_outbox');
    }

    public function testWorkerClaimsPendingBatchWithLimit(): void
    {
        $this->publish(new NeutralHandledEvent('first'));
        $this->publish(new NeutralHandledEvent('second'));

        $this->executeWorker(['--once' => true, '--limit' => '1']);

        $rows = $this->connection->fetchAllAssociative(
            'SELECT event_name, payload, attempts, claimed_by, processed_at FROM shared.async_outbox ORDER BY id ASC',
        );

        $this->assertCount(2, $rows);
        $this->assertSame(1, $this->intValue($rows[0]['attempts']));
        $this->assertNotNull($rows[0]['claimed_by']);
        $this->assertNotNull($rows[0]['processed_at']);
        $this->assertSame(0, $this->intValue($rows[1]['attempts']));
        $this->assertNull($rows[1]['claimed_by']);
        $this->assertNull($rows[1]['processed_at']);
        $this->assertSame(['first'], RecordingIntegrationEventSubscriber::$handledSubjects);
    }

    public function testWorkerClaimsConsumptionBeforeSideEffect(): void
    {
        $this->publish(new NeutralHandledEvent('handled'));

        $this->executeWorker();

        $consumption = $this->fetchLatestConsumptionRow();
        $this->assertSame('processed', $consumption['status']);
        $this->assertNotNull($consumption['claimed_at']);
        $this->assertNotNull($consumption['claimed_by']);
        $this->assertNotNull($consumption['processed_at']);
        $this->assertSame(['handled'], RecordingIntegrationEventSubscriber::$handledSubjects);
    }

    public function testConsumptionClaimPreventsDoubleProcessing(): void
    {
        $this->publish(new NeutralHandledEvent('once'));

        $this->executeWorker();
        $countAfterFirstRun = $this->consumptionCount();

        $this->executeWorker();

        $this->assertSame($countAfterFirstRun, $this->consumptionCount());
        $this->assertSame(['once'], RecordingIntegrationEventSubscriber::$handledSubjects);
    }

    public function testEventWithoutHandlerIsMarkedAsProcessed(): void
    {
        $this->publish(new NeutralNoHandlerEvent('no-handler'));

        $this->executeWorker();

        $outbox = $this->fetchLatestOutboxRow();
        $this->assertNotNull($outbox['processed_at']);
        $this->assertSame(0, $this->consumptionCount());
    }

    public function testHandlerErrorStoresErrorAndAllowsRetry(): void
    {
        $this->publish(new NeutralFailingEvent('retry'));

        $this->executeWorker();

        $failed = $this->fetchLatestOutboxRow();
        $this->assertSame(1, $this->intValue($failed['attempts']));
        $this->assertNull($failed['processed_at']);
        $this->assertNull($failed['claimed_by']);
        $this->assertIsString($failed['last_error']);
        $this->assertStringContainsString('Planned handler failure.', $failed['last_error']);
        $this->assertSame(0, $this->consumptionCount());

        $this->executeWorker();

        $processed = $this->fetchLatestOutboxRow();
        $this->assertSame(2, $this->intValue($processed['attempts']));
        $this->assertNotNull($processed['processed_at']);
        $this->assertNull($processed['last_error']);
        $this->assertSame(2, FailingOnceIntegrationEventSubscriber::$attempts);
    }

    public function testProcessedEventIsNotProcessedAgain(): void
    {
        $this->publish(new NeutralHandledEvent('processed'));
        $this->connection->executeStatement('UPDATE shared.async_outbox SET processed_at = :processed_at', ['processed_at' => '2026-04-28 12:05:00']);

        $this->executeWorker();

        $outbox = $this->fetchLatestOutboxRow();
        $this->assertSame(0, $this->intValue($outbox['attempts']));
        $this->assertSame([], RecordingIntegrationEventSubscriber::$handledSubjects);
    }

    public function testWorkerSkipsRowsAtMaxAttempts(): void
    {
        $this->publish(new NeutralHandledEvent('max-attempts'));
        $this->connection->executeStatement('UPDATE shared.async_outbox SET attempts = 5');

        $this->executeWorker();

        $outbox = $this->fetchLatestOutboxRow();
        $this->assertNull($outbox['processed_at']);
        $this->assertSame(5, $this->intValue($outbox['attempts']));
        $this->assertSame([], RecordingIntegrationEventSubscriber::$handledSubjects);
    }

    public function testOutboxOwnershipCheckPreventsMarkingProcessed(): void
    {
        $this->publish(new NeutralOutboxOwnershipEvent('outbox-ownership'));

        $this->executeWorker();

        $outbox = $this->fetchLatestOutboxRow();
        $this->assertSame('external-worker', $outbox['claimed_by']);
        $this->assertNull($outbox['processed_at']);
        $this->assertSame('processed', $this->fetchLatestConsumptionRow()['status']);
    }

    public function testLostConsumptionOwnershipPreventsMarkingOutboxProcessed(): void
    {
        $this->publish(new NeutralConsumptionOwnershipEvent('consumption-ownership'));

        $this->executeWorker();

        $outbox = $this->fetchLatestOutboxRow();
        $this->assertNull($outbox['claimed_by']);
        $this->assertNull($outbox['processed_at']);
        $this->assertIsString($outbox['last_error']);
        $this->assertStringContainsString('ownership was lost', $outbox['last_error']);

        $consumption = $this->fetchLatestConsumptionRow();
        $this->assertSame('processing', $consumption['status']);
        $this->assertSame('external-worker', $consumption['claimed_by']);
        $this->assertNull($consumption['processed_at']);
    }

    private function publish(IntegrationEvent $event): void
    {
        $this->publisher->publish($event);
    }

    /**
     * @param array<string, mixed> $input
     */
    private function executeWorker(array $input = ['--once' => true]): void
    {
        $kernel = self::$kernel;
        $this->assertNotNull($kernel);

        $application = new Application($kernel);
        $tester = new CommandTester($application->find('app:process-outbox'));
        $exitCode = $tester->execute($input);

        $this->assertSame(0, $exitCode, $tester->getDisplay());
    }

    /**
     * @return array<string, mixed>
     */
    private function fetchLatestOutboxRow(): array
    {
        $row = $this->connection->fetchAssociative('SELECT * FROM shared.async_outbox ORDER BY id DESC LIMIT 1');
        $this->assertIsArray($row);

        return $row;
    }

    /**
     * @return array<string, mixed>
     */
    private function fetchLatestConsumptionRow(): array
    {
        $row = $this->connection->fetchAssociative('SELECT * FROM shared.async_consumption ORDER BY id DESC LIMIT 1');
        $this->assertIsArray($row);

        return $row;
    }

    private function consumptionCount(): int
    {
        return $this->intValue($this->connection->fetchOne('SELECT COUNT(*) FROM shared.async_consumption'));
    }

    private function intValue(mixed $value): int
    {
        $this->assertTrue(\is_int($value) || \is_string($value));

        return (int) $value;
    }
}
