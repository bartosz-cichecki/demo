<?php

declare(strict_types=1);

namespace App\SharedKernel\Application\EventBus;

use App\SharedKernel\Domain\Event\DomainEvent;

interface EventBusInterface
{
    public function dispatch(DomainEvent $event): void;
}
