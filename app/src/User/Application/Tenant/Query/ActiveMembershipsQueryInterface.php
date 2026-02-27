<?php

declare(strict_types=1);

namespace App\User\Application\Tenant\Query;

use App\SharedKernel\Domain\ValueObject\Id;
use App\User\Application\Tenant\Query\Dto\ActiveMembershipDto;

interface ActiveMembershipsQueryInterface
{
    /**
     * @return array<ActiveMembershipDto>
     */
    public function listForUser(Id $userId): array;
}
