<?php

declare(strict_types=1);

namespace App\User\Application\User\Saga;

use App\SharedKernel\Application\CommandBus\CommandBusInterface;
use App\User\Application\User\Command\LogInUserByEmail\LogInUserByEmailCommand;
use App\User\Domain\OtpChallenge\Event\OtpChallengeVerified;

final readonly class UserSaga
{
    public function __construct(
        private CommandBusInterface $commandBus,
    ) {
    }

    public function onOtpChallengeVerified(OtpChallengeVerified $event): void
    {
        $this->commandBus->dispatch(new LogInUserByEmailCommand($event->email));
    }
}
