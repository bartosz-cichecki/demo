<?php

declare(strict_types=1);

namespace App\User\Application\Tenant\Query;

use App\SharedKernel\Domain\ValueObject\Id;
use App\User\Application\Tenant\Query\Dto\MembershipDto;

interface MembershipForClientQueryInterface
{
    public function findForUserAndClient(Id $userId, Id $clientId): ?MembershipDto;
}
