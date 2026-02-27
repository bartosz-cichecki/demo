<?php

declare(strict_types=1);

namespace App\Tests\Client\Domain;

use App\Client\Domain\Client\Client;
use App\Client\Domain\Client\Event\ClientCreated;
use App\Client\Domain\Client\Event\ClientDeactivated;
use App\Client\Domain\Client\Event\ClientDescriptionChanged;
use App\Client\Domain\Client\Event\ClientRenamed;
use App\SharedKernel\Domain\Event\InMemoryDomainEventsCollector;
use App\SharedKernel\Domain\ValueObject\DateTime;
use App\SharedKernel\Domain\ValueObject\Id;
use PHPUnit\Framework\TestCase;

final class ClientTest extends TestCase
{
    // ========================================
    // Construction
    // ========================================

    public function testCannotCreateClientWithEmptyName(): void
    {
        // Given: empty name
        $outside = new FakeClientOutside(new InMemoryDomainEventsCollector());

        // Then: exception is thrown
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Client name cannot be empty.');

        // When: attempting to create client
        new Client($outside, Id::new(), '', null);
    }

    public function testCannotCreateClientWithWhitespaceOnlyName(): void
    {
        // Given: whitespace-only name
        $outside = new FakeClientOutside(new InMemoryDomainEventsCollector());

        // Then: exception is thrown
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Client name cannot be empty.');

        // When: attempting to create client
        new Client($outside, Id::new(), '   ', null);
    }

    public function testCannotCreateClientWithNameExceeding120Characters(): void
    {
        // Given: name longer than 120 characters
        $outside = new FakeClientOutside(new InMemoryDomainEventsCollector());
        $longName = str_repeat('a', 121);

        // Then: exception is thrown
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Client name is too long.');

        // When: attempting to create client
        new Client($outside, Id::new(), $longName, null);
    }

    public function testClientAcceptsNameWithExactly120Characters(): void
    {
        // Given: name with exactly 120 characters
        $outside = new FakeClientOutside(new InMemoryDomainEventsCollector());
        $exactName = str_repeat('a', 120);

        // When: creating client
        $client = new Client($outside, Id::new(), $exactName, null);

        // Then: client is created (no exception)
        $this->assertInstanceOf(Client::class, $client);
    }

    public function testConstructionRecordsClientCreatedEvent(): void
    {
        // Given: valid client data
        $collector = new InMemoryDomainEventsCollector();
        $outside = new FakeClientOutside($collector);
        $id = Id::new();

        // When: creating a new client
        new Client($outside, $id, 'Acme Corp', 'Some description');

        // Then: ClientCreated event is recorded
        $events = $collector->pull();
        $this->assertCount(1, $events);
        $this->assertInstanceOf(ClientCreated::class, $events[0]);
        $this->assertTrue($events[0]->clientId->equals($id));
        $this->assertSame('Acme Corp', $events[0]->name);
        $this->assertSame('Some description', $events[0]->description);
    }

    // ========================================
    // Rename
    // ========================================

    public function testCannotRenameToEmptyName(): void
    {
        // Given: an active client
        [$client] = $this->createActiveClient();

        // Then: exception is thrown
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Client name cannot be empty.');

        // When: renaming to empty
        $client->rename('');
    }

    public function testCannotRenameToNameExceeding120Characters(): void
    {
        // Given: an active client
        [$client] = $this->createActiveClient();
        $longName = str_repeat('b', 121);

        // Then: exception is thrown
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Client name is too long.');

        // When: renaming to long name
        $client->rename($longName);
    }

    public function testInactiveClientCannotBeRenamed(): void
    {
        // Given: an inactive client
        [$client] = $this->createActiveClient();
        $client->deactivate();

        // Then: exception is thrown
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Inactive client cannot be changed.');

        // When: attempting to rename
        $client->rename('New Name');
    }

    public function testRenameRecordsClientRenamedEvent(): void
    {
        // Given: an active client
        [$client, $collector, $id] = $this->createActiveClient();

        // When: renaming with whitespace
        $client->rename('  New Name  ');

        // Then: ClientRenamed event is recorded with trimmed name
        $events = $collector->pull();
        $this->assertCount(1, $events);
        $this->assertInstanceOf(ClientRenamed::class, $events[0]);
        $this->assertTrue($events[0]->clientId->equals($id));
        $this->assertSame('New Name', $events[0]->newName);
        $this->assertInstanceOf(DateTime::class, $events[0]->occurredAt);
    }

    public function testRenameIsIdempotent(): void
    {
        // Given: a client with name "Test Client"
        [$client, $collector] = $this->createActiveClient();

        // When: renaming to the same name (with whitespace)
        $client->rename('  Test Client  ');

        // Then: no events are recorded
        $events = $collector->pull();
        $this->assertCount(0, $events);
    }

    // ========================================
    // Change description
    // ========================================

    public function testInactiveClientCannotChangeDescription(): void
    {
        // Given: an inactive client
        [$client] = $this->createActiveClient();
        $client->deactivate();

        // Then: exception is thrown
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Inactive client cannot be changed.');

        // When: attempting to change description
        $client->changeDescription('New description');
    }

    public function testChangeDescriptionRecordsClientDescriptionChangedEvent(): void
    {
        // Given: an active client
        [$client, $collector, $id] = $this->createActiveClient();

        // When: changing description with whitespace
        $client->changeDescription('  New description  ');

        // Then: ClientDescriptionChanged event is recorded with trimmed description
        $events = $collector->pull();
        $this->assertCount(1, $events);
        $this->assertInstanceOf(ClientDescriptionChanged::class, $events[0]);
        $this->assertTrue($events[0]->clientId->equals($id));
        $this->assertSame('New description', $events[0]->newDescription);
        $this->assertInstanceOf(DateTime::class, $events[0]->occurredAt);
    }

    public function testChangeDescriptionIsIdempotent(): void
    {
        // Given: a client with description "Test description"
        [$client, $collector] = $this->createActiveClient();

        // When: changing to the same description (with whitespace)
        $client->changeDescription('  Test description  ');

        // Then: no events are recorded
        $events = $collector->pull();
        $this->assertCount(0, $events);
    }

    public function testChangeDescriptionToNullRecordsEvent(): void
    {
        // Given: a client with description
        [$client, $collector, $id] = $this->createActiveClient();

        // When: setting description to null
        $client->changeDescription(null);

        // Then: ClientDescriptionChanged event is recorded
        $events = $collector->pull();
        $this->assertCount(1, $events);
        $this->assertInstanceOf(ClientDescriptionChanged::class, $events[0]);
        $this->assertNull($events[0]->newDescription);
    }

    // ========================================
    // Deactivate
    // ========================================

    public function testDeactivateRecordsClientDeactivatedEvent(): void
    {
        // Given: an active client
        [$client, $collector, $id] = $this->createActiveClient();

        // When: deactivating
        $client->deactivate();

        // Then: ClientDeactivated event is recorded
        $events = $collector->pull();
        $this->assertCount(1, $events);
        $this->assertInstanceOf(ClientDeactivated::class, $events[0]);
        $this->assertTrue($events[0]->clientId->equals($id));
        $this->assertInstanceOf(DateTime::class, $events[0]->occurredAt);
    }

    public function testDeactivateIsIdempotent(): void
    {
        // Given: an already deactivated client
        [$client, $collector] = $this->createActiveClient();
        $client->deactivate();
        $collector->pull(); // clear first event

        // When: deactivating again
        $client->deactivate();

        // Then: no new events are recorded
        $events = $collector->pull();
        $this->assertCount(0, $events);
    }

    // ========================================
    // Helpers
    // ========================================

    /**
     * @return array{Client, InMemoryDomainEventsCollector, Id}
     */
    private function createActiveClient(): array
    {
        $collector = new InMemoryDomainEventsCollector();
        $id = Id::new();
        $client = new Client(
            new FakeClientOutside($collector),
            $id,
            'Test Client',
            'Test description',
        );
        $collector->pull(); // clear ClientCreated event from construction

        return [$client, $collector, $id];
    }
}
