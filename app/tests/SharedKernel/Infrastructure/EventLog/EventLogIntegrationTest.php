<?php

declare(strict_types=1);

namespace App\Tests\SharedKernel\Infrastructure\EventLog;

use App\Client\Domain\Client\Event\ClientCreated;
use App\SharedKernel\Application\EventLog\EventLogInterface;
use App\SharedKernel\Domain\Clock\MutableClock;
use App\SharedKernel\Domain\ValueObject\DateTime;
use App\SharedKernel\Domain\ValueObject\Id;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class EventLogIntegrationTest extends KernelTestCase
{
    private EventLogInterface $eventLog;
    private Connection $connection;
    private MutableClock $clock;

    protected function setUp(): void
    {
        self::bootKernel();

        $container = static::getContainer();

        /** @var \Symfony\Bundle\FrameworkBundle\Test\TestContainer $testContainer */
        $testContainer = $container->get('test.service_container');

        $eventLog = $testContainer->get(EventLogInterface::class);
        $connection = $testContainer->get(Connection::class);
        $clock = $testContainer->get(MutableClock::class);

        $this->assertInstanceOf(EventLogInterface::class, $eventLog);
        $this->assertInstanceOf(Connection::class, $connection);
        $this->assertInstanceOf(MutableClock::class, $clock);

        $this->eventLog = $eventLog;
        $this->connection = $connection;
        $this->clock = $clock;
    }

    public function testItPersistsClientCreatedEventToEventLog(): void
    {
        $uuid = '550e8400-e29b-41d4-a716-446655440000';
        $id = new Id($uuid);
        $dt = new \DateTimeImmutable('2026-01-01T00:00:00+00:00');
        $occurredAt = new DateTime($dt);
        $name = 'Acme Corp';
        $description = null;
        $this->clock->set(DateTime::fromStorageString('2026-04-28 12:00:00'));

        $event = new ClientCreated($id, $name, $description, $occurredAt);

        $this->eventLog->save($event);

        $row = $this->connection->fetchAssociative(
            'SELECT event_name, occurred_at::text AS occurred_at, payload FROM shared.event_log WHERE event_name = :event_name ORDER BY id DESC LIMIT 1',
            ['event_name' => $event::class],
        );

        $this->assertIsArray($row);
        $this->assertSame(ClientCreated::class, $row['event_name']);
        $this->assertSame('2026-04-28 12:00:00', $row['occurred_at']);
        $this->assertIsString($row['payload']);

        $payload = json_decode($row['payload'], true, flags: \JSON_THROW_ON_ERROR);
        $this->assertIsArray($payload);
        $this->assertSame($uuid, $payload['clientId']);
        $this->assertSame($name, $payload['name']);
        $this->assertNull($payload['description']);
        $this->assertSame($dt->format(\DATE_ATOM), $payload['occurredAt']);
    }

    public function testItPersistsClientCreatedWithDescriptionToEventLog(): void
    {
        $uuid = '660e8400-e29b-41d4-a716-446655440001';
        $id = new Id($uuid);
        $dt = new \DateTimeImmutable('2026-02-01T12:30:00+00:00');
        $occurredAt = new DateTime($dt);
        $name = 'Beta Inc';
        $description = 'A test client';
        $this->clock->set(DateTime::fromStorageString('2026-04-28 12:30:00'));

        $event = new ClientCreated($id, $name, $description, $occurredAt);

        $this->eventLog->save($event);

        $row = $this->connection->fetchAssociative(
            'SELECT event_name, occurred_at::text AS occurred_at, payload FROM shared.event_log WHERE event_name = :event_name ORDER BY id DESC LIMIT 1',
            ['event_name' => $event::class],
        );

        $this->assertIsArray($row);
        $this->assertSame(ClientCreated::class, $row['event_name']);
        $this->assertSame('2026-04-28 12:30:00', $row['occurred_at']);
        $this->assertIsString($row['payload']);

        $payload = json_decode($row['payload'], true, flags: \JSON_THROW_ON_ERROR);
        $this->assertIsArray($payload);
        $this->assertSame($uuid, $payload['clientId']);
        $this->assertSame($name, $payload['name']);
        $this->assertSame($description, $payload['description']);
        $this->assertSame($dt->format(\DATE_ATOM), $payload['occurredAt']);
    }
}
