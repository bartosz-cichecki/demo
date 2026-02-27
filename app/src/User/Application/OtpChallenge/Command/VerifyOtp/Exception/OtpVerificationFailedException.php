<?php

declare(strict_types=1);

namespace App\User\Application\OtpChallenge\Command\VerifyOtp\Exception;

final class OtpVerificationFailedException extends \RuntimeException
{
    protected $message = 'OTP verification failed';
}
