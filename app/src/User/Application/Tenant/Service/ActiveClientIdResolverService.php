<?php

declare(strict_types=1);

namespace App\User\Application\Tenant\Service;

use App\SharedKernel\Domain\ValueObject\Id;
use App\User\Application\Tenant\Query\ActiveMembershipsQueryInterface;
use App\User\Application\Tenant\Query\Dto\ActiveMembershipDto;

final readonly class ActiveClientIdResolverService
{
    public function __construct(
        private ActiveMembershipsQueryInterface $activeMembershipsQuery,
    ) {
    }

    public function resolve(Id $userId): ActiveClientIdResolution
    {
        $memberships = $this->activeMembershipsQuery->listForUser($userId);
        if ([] === $memberships) {
            return ActiveClientIdResolution::deny();
        }

        usort(
            $memberships,
            static fn (ActiveMembershipDto $left, ActiveMembershipDto $right): int => $left->clientId <=> $right->clientId,
        );

        return ActiveClientIdResolution::allow(
            clientId: $memberships[0]->clientId,
            warning: \count($memberships) > 1,
        );
    }
}
