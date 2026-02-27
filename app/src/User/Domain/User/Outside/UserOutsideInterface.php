<?php

declare(strict_types=1);

namespace App\User\Domain\User\Outside;

use App\SharedKernel\Domain\Event\DomainEvent;
use App\SharedKernel\Domain\ValueObject\DateTime;

interface UserOutsideInterface
{
    public function now(): DateTime;

    public function record(DomainEvent $event): void;
}
