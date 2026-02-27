<?php

declare(strict_types=1);

namespace App\Client\Infrastructure\Client;

use App\Client\Domain\Client\Outside\ClientOutsideInterface;
use App\SharedKernel\Domain\Event\DomainEvent;
use App\SharedKernel\Domain\Event\DomainEventsRecorder;
use App\SharedKernel\Domain\ValueObject\DateTime;
use App\SharedKernel\Infrastructure\Outside\Attribute\AsOutsideFor;

#[AsOutsideFor(ClientOutsideInterface::class)]
final readonly class ClientOutside implements ClientOutsideInterface
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
