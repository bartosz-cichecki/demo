<?php

declare(strict_types=1);

namespace App\User\Application\OtpChallenge\Command\VerifyOtp;

use App\SharedKernel\Application\CommandBus\CommandInterface;
use App\SharedKernel\Domain\ValueObject\Email;

final readonly class VerifyOtpCommand implements CommandInterface
{
    public function __construct(
        public Email $email,
        public string $code,
    ) {
    }
}
