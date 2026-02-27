<?php

declare(strict_types=1);

namespace App\Tests\User\Domain;

use App\SharedKernel\Domain\Event\DomainEvent;
use App\SharedKernel\Domain\Event\DomainEventsRecorder;
use App\SharedKernel\Domain\ValueObject\DateTime;
use App\User\Domain\User\Outside\UserOutsideInterface;

final class FakeUserOutside implements UserOutsideInterface
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
