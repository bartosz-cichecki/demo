<?php

declare(strict_types=1);

namespace App\Client\Application\UserAccount\Query;

use App\SharedKernel\Domain\ValueObject\Email;
use App\SharedKernel\Domain\ValueObject\Id;

/**
 * Client's own read port for user accounts owned by User (ACL, architecture §4.1).
 */
interface UserAccountQueryInterface
{
    public function findEmailByUserId(Id $userId): ?Email;

    public function findUserIdByEmail(Email $email): ?Id;
}
