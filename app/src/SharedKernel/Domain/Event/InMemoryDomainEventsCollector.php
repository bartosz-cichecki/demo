<?php

declare(strict_types=1);

namespace App\SharedKernel\Domain\Event;

final class InMemoryDomainEventsCollector implements DomainEventsRecorder, DomainEventsBuffer
{
    /** @var DomainEvent[] */
    private array $events = [];

    public function record(DomainEvent $event): void
    {
        $this->events[] = $event;
    }

    public function poll(): ?DomainEvent
    {
        return array_shift($this->events);
    }

    public function clear(): void
    {
        $this->events = [];
    }

    /**
     * @return DomainEvent[]
     */
    public function peek(): array
    {
        return $this->events;
    }

    /**
     * @return DomainEvent[]
     */
    public function pull(): array
    {
        $events = $this->events;
        $this->events = [];

        return $events;
    }
}
