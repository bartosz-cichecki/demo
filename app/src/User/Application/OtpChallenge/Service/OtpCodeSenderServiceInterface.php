<?php

declare(strict_types=1);

namespace App\User\Application\OtpChallenge\Service;

use App\SharedKernel\Domain\ValueObject\Email;

interface OtpCodeSenderServiceInterface
{
    public function send(Email $email, string $plainCode): void;
}
