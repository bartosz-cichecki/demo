<?php

declare(strict_types=1);

namespace App\User\Infrastructure\User;

use App\SharedKernel\Domain\Event\DomainEvent;
use App\SharedKernel\Domain\Event\DomainEventsRecorder;
use App\SharedKernel\Domain\ValueObject\DateTime;
use App\SharedKernel\Infrastructure\Outside\Attribute\AsOutsideFor;
use App\User\Domain\User\Outside\UserOutsideInterface;

#[AsOutsideFor(UserOutsideInterface::class)]
final readonly class UserOutside implements UserOutsideInterface
{
    public function __construct(
        private DomainEventsRecorder $domainEventsRecorder,
    ) {
    }

    public function now(): DateTime
    {
        return DateTime::now();
    }

    public function record(DomainEvent $event): void
    {
        $this->domainEventsRecorder->record($event);
    }
}
