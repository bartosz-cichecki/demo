<?php

declare(strict_types=1);

namespace App\Tests\Client\Domain;

use App\Client\Domain\Client\Outside\ClientOutsideInterface;
use App\SharedKernel\Domain\Event\DomainEvent;
use App\SharedKernel\Domain\Event\DomainEventsRecorder;
use App\SharedKernel\Domain\ValueObject\DateTime;

final class FakeClientOutside implements ClientOutsideInterface
{
    public function __construct(
        private readonly DomainEventsRecorder $recorder,
        private readonly \DateTimeImmutable $fixedTime = new \DateTimeImmutable('2024-01-15 10:00:00'),
    ) {
    }

    public function now(): DateTime
    {
        return new DateTime($this->fixedTime);
    }

    public function record(DomainEvent $event): void
    {
        $this->recorder->record($event);
    }
}
