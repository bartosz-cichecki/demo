<?php

declare(strict_types=1);

namespace App\SharedKernel\Application\EventLog;

use App\SharedKernel\Domain\Event\DomainEvent;

interface EventLogInterface
{
    public function save(DomainEvent $event): void;
}
