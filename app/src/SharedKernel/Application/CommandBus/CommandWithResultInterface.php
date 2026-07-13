<?php

declare(strict_types=1);

namespace App\SharedKernel\Application\CommandBus;

/**
 * @template TResult of object
 */
interface CommandWithResultInterface extends CommandInterface
{
}
