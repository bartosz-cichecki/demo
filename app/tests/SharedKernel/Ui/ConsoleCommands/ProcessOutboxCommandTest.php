<?php

declare(strict_types=1);

namespace App\Tests\SharedKernel\Ui\ConsoleCommands;

use App\SharedKernel\Application\IntegrationEvent\IntegrationEvent;
use App\SharedKernel\Domain\Clock\MutableClock;
use App\SharedKernel\Ui\ConsoleCommands\ProcessOutboxCommand;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;

final class ProcessOutboxCommandTest extends KernelTestCase
{
    private Connection $connection;
    private DenormalizerInterface $denormalizer;
    private MutableClock $clock;

    protected function setUp(): void
    {
        self::bootKernel();

        $container = static::getContainer();

        /** @var \Symfony\Bundle\FrameworkBundle\Test\TestContainer $testContainer */
        $testContainer = $container->get('test.service_container');

        $connection = $testContainer->get(Connection::class);
        $denormalizer = $testContainer->get(DenormalizerInterface::class);
        $clock = $testContainer->get(MutableClock::class);

        $this->assertInstanceOf(Connection::class, $connection);
        $this->assertInstanceOf(DenormalizerInterface::class, $denormalizer);
        $this->assertInstanceOf(MutableClock::class, $clock);

        $this->connection = $connection;
        $this->denormalizer = $denormalizer;
        $this->clock = $clock;
        $this->clock->set(new \DateTimeImmutable('2026-04-28 12:00:00', new \DateTimeZone('UTC')));

        $this->connection->executeStatement('DELETE FROM shared.async_consumption');
        $this->connection->executeStatement('DELETE FROM shared.async_outbox');
    }

    public function testItClaimsPendingBatchAndRespectsLimit(): void
    {
        $firstEventId = '10000000-0000-4000-8000-000000000001';
        $secondEventId = '10000000-0000-4000-8000-000000000002';
        $this->insertOutbox($firstEventId, new NeutralOutboxEvent('first'));
        $this->insertOutbox($secondEventId, new NeutralOutboxEvent('second'));

        $subscriber = new RecordingOutboxSubscriber();

        $this->runCommand([$subscriber], ['--limit' => '1']);

        $rows = $this->connection->fetchAllAssociative(
            'SELECT event_id, attempts, claimed_by, processed_at FROM shared.async_outbox ORDER BY id ASC',
        );

        $this->assertCount(2, $rows);
        $this->assertSame($firstEventId, $rows[0]['event_id']);
        $this->assertSame(1, $this->intValue($rows[0]['attempts']));
        $this->assertNotNull($rows[0]['claimed_by']);
        $this->assertNotNull($rows[0]['processed_at']);
        $this->assertSame($secondEventId, $rows[1]['event_id']);
        $this->assertSame(0, $this->intValue($rows[1]['attempts']));
        $this->assertNull($rows[1]['claimed_by']);
        $this->assertNull($rows[1]['processed_at']);
        $this->assertSame(['first'], $subscriber->handledSubjects);
    }

    public function testItExecutesMatchingHandler(): void
    {
        $this->insertOutbox('10000000-0000-4000-8000-000000000003', new NeutralOutboxEvent('matched'));

        $subscriber = new RecordingOutboxSubscriber();

        $this->runCommand([$subscriber]);

        $this->assertSame(['matched'], $subscriber->handledSubjects);
        $this->assertSame(1, $this->processedOutboxCount());
    }

    public function testItExecutesHandlerAtMostOnceForEventAndMethod(): void
    {
        $eventId = '10000000-0000-4000-8000-000000000004';
        $this->insertOutbox($eventId, new NeutralOutboxEvent('already-processed'));
        $this->insertConsumption($eventId, RecordingOutboxSubscriber::class, 'onNeutralOutbox', 'processed');

        $subscriber = new RecordingOutboxSubscriber();

        $this->runCommand([$subscriber]);

        $this->assertSame([], $subscriber->handledSubjects);
        $this->assertSame(1, $this->processedOutboxCount());
    }

    public function testItMarksEventWithoutHandlerAsProcessed(): void
    {
        $this->insertOutbox('10000000-0000-4000-8000-000000000005', new NeutralOutboxEvent('no-handler'));

        $this->runCommand([]);

        $this->assertSame(1, $this->processedOutboxCount());
    }

    public function testHandlerErrorStoresShortErrorAndAllowsRetry(): void
    {
        $eventId = '10000000-0000-4000-8000-000000000006';
        $this->insertOutbox($eventId, new NeutralOutboxEvent('retry'));

        $subscriber = new FailingOnceOutboxSubscriber();

        $this->runCommand([$subscriber]);

        $failedRow = $this->connection->fetchAssociative(
            'SELECT attempts, last_error, claimed_by, processed_at FROM shared.async_outbox WHERE event_id = :event_id',
            ['event_id' => $eventId],
            ['event_id' => Types::GUID],
        );

        $this->assertIsArray($failedRow);
        $this->assertSame(1, $this->intValue($failedRow['attempts']));
        $this->assertIsString($failedRow['last_error']);
        $this->assertStringContainsString('Planned handler failure.', $failedRow['last_error']);
        $this->assertNull($failedRow['claimed_by']);
        $this->assertNull($failedRow['processed_at']);
        $this->assertSame(0, $this->consumptionCount());

        $this->runCommand([$subscriber]);

        $processedRow = $this->connection->fetchAssociative(
            'SELECT attempts, last_error, processed_at FROM shared.async_outbox WHERE event_id = :event_id',
            ['event_id' => $eventId],
            ['event_id' => Types::GUID],
        );

        $this->assertIsArray($processedRow);
        $this->assertSame(2, $this->intValue($processedRow['attempts']));
        $this->assertNull($processedRow['last_error']);
        $this->assertNotNull($processedRow['processed_at']);
        $this->assertSame(2, $subscriber->attempts);
    }

    public function testProcessedEventIsNotProcessedAgain(): void
    {
        $eventId = '10000000-0000-4000-8000-000000000007';
        $this->insertOutbox($eventId, new NeutralOutboxEvent('processed'), processed: true);

        $subscriber = new RecordingOutboxSubscriber();

        $this->runCommand([$subscriber]);

        $row = $this->connection->fetchAssociative(
            'SELECT attempts FROM shared.async_outbox WHERE event_id = :event_id',
            ['event_id' => $eventId],
            ['event_id' => Types::GUID],
        );

        $this->assertIsArray($row);
        $this->assertSame(0, $this->intValue($row['attempts']));
        $this->assertSame([], $subscriber->handledSubjects);
    }

    public function testOwnershipCheckPreventsMarkingOutboxAsProcessed(): void
    {
        $eventId = '10000000-0000-4000-8000-000000000008';
        $this->insertOutbox($eventId, new NeutralOutboxEvent($eventId));

        $subscriber = new OwnershipChangingOutboxSubscriber($this->connection);

        $this->runCommand([$subscriber]);

        $row = $this->connection->fetchAssociative(
            'SELECT claimed_by, processed_at FROM shared.async_outbox WHERE event_id = :event_id',
            ['event_id' => $eventId],
            ['event_id' => Types::GUID],
        );

        $this->assertIsArray($row);
        $this->assertSame('external-worker', $row['claimed_by']);
        $this->assertNull($row['processed_at']);
        $this->assertSame(1, $this->processedConsumptionCount());
    }

    public function testLostHandlerClaimOwnershipPreventsMarkingOutboxAsProcessed(): void
    {
        $eventId = '10000000-0000-4000-8000-000000000009';
        $this->insertOutbox($eventId, new NeutralOutboxEvent($eventId));

        $subscriber = new ConsumptionOwnershipChangingOutboxSubscriber($this->connection);

        $this->runCommand([$subscriber]);

        $outbox = $this->connection->fetchAssociative(
            'SELECT claimed_by, processed_at, last_error FROM shared.async_outbox WHERE event_id = :event_id',
            ['event_id' => $eventId],
            ['event_id' => Types::GUID],
        );

        $this->assertIsArray($outbox);
        $this->assertNull($outbox['claimed_by']);
        $this->assertNull($outbox['processed_at']);
        $this->assertIsString($outbox['last_error']);
        $this->assertStringContainsString('ownership was lost', $outbox['last_error']);

        $consumption = $this->connection->fetchAssociative(
            'SELECT status, claimed_by, processed_at FROM shared.async_consumption WHERE event_id = :event_id',
            ['event_id' => $eventId],
            ['event_id' => Types::GUID],
        );

        $this->assertIsArray($consumption);
        $this->assertSame('processing', $consumption['status']);
        $this->assertSame('external-worker', $consumption['claimed_by']);
        $this->assertNull($consumption['processed_at']);
    }

    /**
     * @param iterable<object>     $subscribers
     * @param array<string, mixed> $options
     */
    private function runCommand(iterable $subscribers, array $options = []): void
    {
        $command = new ProcessOutboxCommand($this->connection, $this->denormalizer, $this->clock, $subscribers);
        $tester = new CommandTester($command);

        $exitCode = $tester->execute(array_replace(['--once' => true], $options));

        $this->assertSame(0, $exitCode, $tester->getDisplay());
    }

    private function insertOutbox(string $eventId, IntegrationEvent $event, bool $processed = false): void
    {
        $this->connection->executeStatement(
            <<<'SQL'
                INSERT INTO shared.async_outbox (event_id, event_name, payload, created_at, processed_at)
                VALUES (:event_id, :event_name, :payload, :created_at, :processed_at)
                SQL,
            [
                'event_id' => $eventId,
                'event_name' => $event::class,
                'payload' => ['subject' => $event instanceof NeutralOutboxEvent ? $event->subject : 'neutral'],
                'created_at' => '2026-04-28 10:00:00',
                'processed_at' => $processed ? '2026-04-28 10:05:00' : null,
            ],
            [
                'event_id' => Types::GUID,
                'event_name' => Types::STRING,
                'payload' => Types::JSON,
                'created_at' => Types::STRING,
                'processed_at' => Types::STRING,
            ],
        );
    }

    /**
     * @param class-string $subscriber
     */
    private function insertConsumption(string $eventId, string $subscriber, string $handlerMethod, string $status): void
    {
        $this->connection->executeStatement(
            <<<'SQL'
                INSERT INTO shared.async_consumption (event_id, subscriber, handler_method, status, claimed_at, claimed_by, processed_at)
                VALUES (:event_id, :subscriber, :handler_method, :status, :claimed_at, :claimed_by, :processed_at)
                SQL,
            [
                'event_id' => $eventId,
                'subscriber' => $subscriber,
                'handler_method' => $handlerMethod,
                'status' => $status,
                'claimed_at' => '2026-04-28 10:00:00',
                'claimed_by' => 'previous-worker',
                'processed_at' => 'processed' === $status ? '2026-04-28 10:01:00' : null,
            ],
            [
                'event_id' => Types::GUID,
                'subscriber' => Types::STRING,
                'handler_method' => Types::STRING,
                'status' => Types::STRING,
                'claimed_at' => Types::STRING,
                'claimed_by' => Types::STRING,
                'processed_at' => Types::STRING,
            ],
        );
    }

    private function processedOutboxCount(): int
    {
        return $this->intValue($this->connection->fetchOne('SELECT COUNT(*) FROM shared.async_outbox WHERE processed_at IS NOT NULL'));
    }

    private function consumptionCount(): int
    {
        return $this->intValue($this->connection->fetchOne('SELECT COUNT(*) FROM shared.async_consumption'));
    }

    private function processedConsumptionCount(): int
    {
        return $this->intValue($this->connection->fetchOne("SELECT COUNT(*) FROM shared.async_consumption WHERE status = 'processed'"));
    }

    private function intValue(mixed $value): int
    {
        $this->assertTrue(\is_int($value) || \is_string($value));

        return (int) $value;
    }
}

final readonly class NeutralOutboxEvent implements IntegrationEvent
{
    public function __construct(
        public string $subject,
    ) {
    }
}

final class RecordingOutboxSubscriber
{
    /**
     * @var list<string>
     */
    public array $handledSubjects = [];

    public function onNeutralOutbox(NeutralOutboxEvent $event): void
    {
        $this->handledSubjects[] = $event->subject;
    }
}

final class FailingOnceOutboxSubscriber
{
    public int $attempts = 0;

    public function onNeutralOutbox(NeutralOutboxEvent $event): void
    {
        ++$this->attempts;

        if (1 === $this->attempts) {
            throw new \RuntimeException('Planned handler failure.');
        }
    }
}

final readonly class OwnershipChangingOutboxSubscriber
{
    public function __construct(
        private Connection $connection,
    ) {
    }

    public function onNeutralOutbox(NeutralOutboxEvent $event): void
    {
        $this->connection->executeStatement(
            'UPDATE shared.async_outbox SET claimed_by = :claimed_by WHERE event_id = :event_id',
            [
                'claimed_by' => 'external-worker',
                'event_id' => $event->subject,
            ],
            [
                'claimed_by' => Types::STRING,
                'event_id' => Types::GUID,
            ],
        );
    }
}

final readonly class ConsumptionOwnershipChangingOutboxSubscriber
{
    public function __construct(
        private Connection $connection,
    ) {
    }

    public function onNeutralOutbox(NeutralOutboxEvent $event): void
    {
        $this->connection->executeStatement(
            'UPDATE shared.async_consumption SET claimed_by = :claimed_by WHERE event_id = :event_id AND subscriber = :subscriber AND handler_method = :handler_method',
            [
                'claimed_by' => 'external-worker',
                'event_id' => $event->subject,
                'subscriber' => self::class,
                'handler_method' => 'onNeutralOutbox',
            ],
            [
                'claimed_by' => Types::STRING,
                'event_id' => Types::GUID,
                'subscriber' => Types::STRING,
                'handler_method' => Types::STRING,
            ],
        );
    }
}
