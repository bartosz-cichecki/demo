<?php

declare(strict_types=1);

namespace App\Tests\SharedKernel\Infrastructure\EventBus;

use App\SharedKernel\Domain\Event\DomainEvent;
use App\SharedKernel\Infrastructure\EventBus\EventBus;
use PHPUnit\Framework\TestCase;

final class EventBusTest extends TestCase
{
    public function testDispatchCallsMatchingSagaMethod(): void
    {
        $saga = new DummySaga();
        $event = new DummyEvent();

        $bus = new EventBus([$saga]);
        $bus->dispatch($event);

        $this->assertSame([$event], $saga->handled);
    }

    public function testDispatchIgnoresNonMatchingEvents(): void
    {
        $saga = new DummySaga();
        $event = new AnotherEvent();

        $bus = new EventBus([$saga]);
        $bus->dispatch($event);

        $this->assertSame([], $saga->handled);
    }

    public function testDispatchCallsMultipleSagas(): void
    {
        $saga1 = new DummySaga();
        $saga2 = new DummySaga();
        $event = new DummyEvent();

        $bus = new EventBus([$saga1, $saga2]);
        $bus->dispatch($event);

        $this->assertSame([$event], $saga1->handled);
        $this->assertSame([$event], $saga2->handled);
    }

    public function testDispatchWithNoSagas(): void
    {
        $bus = new EventBus([]);
        $bus->dispatch(new DummyEvent());

        $this->expectNotToPerformAssertions();
    }
}

final class DummySaga
{
    /** @var DummyEvent[] */
    public array $handled = [];

    public function onDummyEvent(DummyEvent $event): void
    {
        $this->handled[] = $event;
    }
}

final class DummyEvent implements DomainEvent
{
}

final class AnotherEvent implements DomainEvent
{
}
