<?php

declare(strict_types=1);

namespace App\Client\Application\ClientMember\Port;

use App\SharedKernel\Domain\ValueObject\Id;

interface UserProvisioningServiceInterface
{
    public function ensureUserExists(string $email): Id;
}
