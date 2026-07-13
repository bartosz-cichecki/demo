<?php

declare(strict_types=1);

namespace App\User\Application\OtpChallenge\Command\VerifyOtp;

use App\SharedKernel\Application\CommandBus\CommandWithResultInterface;
use App\SharedKernel\Domain\ValueObject\Email;

/**
 * @implements CommandWithResultInterface<VerifyOtpResult>
 */
final readonly class VerifyOtpCommand implements CommandWithResultInterface
{
    public function __construct(
        public Email $email,
        public string $code,
    ) {
    }
}
