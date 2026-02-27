<?php

declare(strict_types=1);

namespace App\User\Application\OtpChallenge\Command\RequestOtp;

use App\SharedKernel\Application\CommandBus\CommandInterface;
use App\SharedKernel\Domain\ValueObject\Email;

final readonly class RequestOtpCommand implements CommandInterface
{
    public function __construct(
        public Email $email,
        public ?string $ipAddress = null,
        public ?string $userAgent = null,
    ) {
    }
}
