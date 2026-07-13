<?php

declare(strict_types=1);

namespace App\SharedKernel\Application\CommandBus;

interface CommandBusInterface
{
    public function dispatch(CommandInterface $command): void;

    /**
     * @template TResult of object
     *
     * @param CommandWithResultInterface<TResult> $command
     *
     * @return TResult
     */
    public function dispatchWithResult(CommandWithResultInterface $command): object;
}
