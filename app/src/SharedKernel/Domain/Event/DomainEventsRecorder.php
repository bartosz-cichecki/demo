<?php

declare(strict_types=1);

namespace App\SharedKernel\Domain\Event;

interface DomainEventsRecorder
{
    public function record(DomainEvent $event): void;
}
