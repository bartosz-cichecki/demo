<?php

declare(strict_types=1);

namespace App\Client\Domain\ClientMember\Factory;

use App\Client\Domain\ClientMember\ClientMember;
use App\SharedKernel\Domain\ValueObject\Id;

interface ClientMemberFactoryInterface
{
    /**
     * @param array<string> $roles
     */
    public function create(
        Id $id,
        Id $clientId,
        Id $userId,
        array $roles,
    ): ClientMember;
}
