<?php

declare(strict_types=1);

namespace App\Tests\SharedKernel\Infrastructure\IntegrationEvent;

use App\SharedKernel\Application\IntegrationEvent\IntegrationEvent;
use App\SharedKernel\Application\IntegrationEvent\IntegrationEventPublisherInterface;
use App\SharedKernel\Domain\Clock\MutableClock;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class DbalOutboxPublisherIntegrationTest extends KernelTestCase
{
    private IntegrationEventPublisherInterface $publisher;
    private MutableClock $clock;
    private Connection $connection;

    protected function setUp(): void
    {
        self::bootKernel();

        $container = static::getContainer();

        /** @var \Symfony\Bundle\FrameworkBundle\Test\TestContainer $testContainer */
        $testContainer = $container->get('test.service_container');

        $publisher = $testContainer->get(IntegrationEventPublisherInterface::class);
        $clock = $testContainer->get(MutableClock::class);
        $connection = $testContainer->get(Connection::class);

        $this->assertInstanceOf(IntegrationEventPublisherInterface::class, $publisher);
        $this->assertInstanceOf(MutableClock::class, $clock);
        $this->assertInstanceOf(Connection::class, $connection);

        $this->publisher = $publisher;
        $this->clock = $clock;
        $this->connection = $connection;
    }

    public function testItPersistsIntegrationEventToOutbox(): void
    {
        $this->clock->set(new \DateTimeImmutable('2026-04-28 10:15:30', new \DateTimeZone('Europe/Warsaw')));
        $event = new NeutralIntegrationEvent('event-subject-123', 'ready');

        $this->publisher->publish($event);

        $row = $this->connection->fetchAssociative(
            'SELECT event_id, event_name, payload, created_at::text AS created_at, claimed_at, claimed_by, processed_at, attempts, last_error FROM shared.async_outbox WHERE event_name = :event_name ORDER BY id DESC LIMIT 1',
            ['event_name' => $event::class],
        );

        $this->assertIsArray($row);
        $this->assertNotEmpty($row['event_id']);
        $this->assertSame(NeutralIntegrationEvent::class, $row['event_name']);
        $this->assertSame('2026-04-28 08:15:30', $row['created_at']);
        $this->assertNull($row['claimed_at']);
        $this->assertNull($row['claimed_by']);
        $this->assertNull($row['processed_at']);
        $this->assertTrue(\is_int($row['attempts']) || \is_string($row['attempts']));
        $this->assertSame(0, (int) $row['attempts']);
        $this->assertNull($row['last_error']);

        $this->assertIsString($row['payload']);
        $payload = json_decode($row['payload'], true, flags: \JSON_THROW_ON_ERROR);

        $this->assertIsArray($payload);
        $this->assertSame('event-subject-123', $payload['subjectId']);
        $this->assertSame('ready', $payload['state']);
    }
}

final readonly class NeutralIntegrationEvent implements IntegrationEvent
{
    public function __construct(
        public string $subjectId,
        public string $state,
    ) {
    }
}
