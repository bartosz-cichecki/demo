<?php

declare(strict_types=1);

namespace App\User\Infrastructure\Tenant;

use App\Client\Application\Client\Query\ClientQueryInterface;
use App\Client\Application\ClientMember\Query\ClientMemberQueryInterface;
use App\SharedKernel\Domain\ValueObject\Id;
use App\User\Application\Tenant\Query\ActiveMembershipsQueryInterface;
use App\User\Application\Tenant\Query\Dto\ActiveMembershipDto;

final readonly class ActiveMembershipsQuery implements ActiveMembershipsQueryInterface
{
    public function __construct(
        private ClientMemberQueryInterface $clientMemberQuery,
        private ClientQueryInterface $clientQuery,
    ) {
    }

    public function listForUser(Id $userId): array
    {
        $activeMemberships = [];
        foreach ($this->clientMemberQuery->listByUser($userId) as $membership) {
            if (!$membership->isActive) {
                continue;
            }

            $client = $this->clientQuery->findById(new Id($membership->clientId));
            if (null === $client) {
                continue;
            }

            $activeMemberships[] = new ActiveMembershipDto(
                clientId: $membership->clientId,
                clientName: $client->name,
                roles: $membership->roles,
            );
        }

        return $activeMemberships;
    }
}
