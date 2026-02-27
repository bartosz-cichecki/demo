<?php

declare(strict_types=1);

namespace App\SharedKernel\Application\CommandBus;

interface CommandBusInterface
{
    public function dispatch(CommandInterface $command): void;
}
