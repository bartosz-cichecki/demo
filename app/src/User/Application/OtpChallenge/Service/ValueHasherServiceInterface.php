<?php

declare(strict_types=1);

namespace App\User\Application\OtpChallenge\Service;

interface ValueHasherServiceInterface
{
    public function hash(string $value): string;
}
