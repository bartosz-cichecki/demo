<?php

declare(strict_types=1);

namespace App\Tests\SharedKernel\Domain\Event;

use App\SharedKernel\Domain\Event\DomainEvent;
use App\SharedKernel\Domain\Event\InMemoryDomainEventsCollector;
use PHPUnit\Framework\TestCase;

final class InMemoryDomainEventsCollectorTest extends TestCase
{
    public function testPollDequeuesFirstEventFifo(): void
    {
        $collector = new InMemoryDomainEventsCollector();
        $event1 = new FakeDomainEvent();
        $event2 = new FakeDomainEvent();

        $collector->record($event1);
        $collector->record($event2);

        $this->assertSame($event1, $collector->poll());
        $this->assertSame($event2, $collector->poll());
        $this->assertNull($collector->poll());
    }

    public function testPollRemovesEventFromBuffer(): void
    {
        $collector = new InMemoryDomainEventsCollector();
        $collector->record(new FakeDomainEvent());
        $collector->record(new FakeDomainEvent());

        $collector->poll();

        $this->assertCount(1, $collector->peek());
    }

    public function testPeekReturnsEventsWithoutClearing(): void
    {
        $collector = new InMemoryDomainEventsCollector();
        $event = new FakeDomainEvent();

        $collector->record($event);

        // First peek
        $events = $collector->peek();
        $this->assertCount(1, $events);
        $this->assertSame($event, $events[0]);

        // Second peek - events still there
        $events = $collector->peek();
        $this->assertCount(1, $events);
        $this->assertSame($event, $events[0]);
    }

    public function testClearRemovesAllEvents(): void
    {
        $collector = new InMemoryDomainEventsCollector();
        $collector->record(new FakeDomainEvent());
        $collector->record(new FakeDomainEvent());

        $this->assertCount(2, $collector->peek());

        $collector->clear();

        $this->assertCount(0, $collector->peek());
    }

    public function testPullReturnsAndClearsEvents(): void
    {
        $collector = new InMemoryDomainEventsCollector();
        $event = new FakeDomainEvent();

        $collector->record($event);

        $events = $collector->pull();
        $this->assertCount(1, $events);
        $this->assertSame($event, $events[0]);

        // After pull, buffer is empty
        $this->assertCount(0, $collector->peek());
    }

    public function testPeekAndClearWorkflow(): void
    {
        $collector = new InMemoryDomainEventsCollector();
        $event1 = new FakeDomainEvent();
        $event2 = new FakeDomainEvent();

        $collector->record($event1);
        $collector->record($event2);

        // Peek to get events into local variable
        $events = $collector->peek();
        $this->assertCount(2, $events);

        // Clear the buffer
        $collector->clear();

        // Buffer is now empty
        $this->assertCount(0, $collector->peek());

        // But our local $events still has the data
        $this->assertCount(2, $events);
        $this->assertSame($event1, $events[0]);
        $this->assertSame($event2, $events[1]);
    }
}

final class FakeDomainEvent implements DomainEvent
{
}
