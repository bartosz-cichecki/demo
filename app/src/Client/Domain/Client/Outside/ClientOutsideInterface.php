<?php

declare(strict_types=1);

namespace App\Client\Domain\Client\Outside;

use App\SharedKernel\Domain\Event\DomainEvent;
use App\SharedKernel\Domain\ValueObject\DateTime;

interface ClientOutsideInterface
{
    public function now(): DateTime;

    public function record(DomainEvent $event): void;
}
