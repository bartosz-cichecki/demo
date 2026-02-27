<?php

declare(strict_types=1);

namespace App\SharedKernel\Domain\Event;

interface DomainEventsBuffer
{
    /**
     * Dequeues the first event from the buffer (FIFO).
     * Returns null when the buffer is empty.
     */
    public function poll(): ?DomainEvent;

    public function clear(): void;
}
