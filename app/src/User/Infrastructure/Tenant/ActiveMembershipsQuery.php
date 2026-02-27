<?php

declare(strict_types=1);

namespace App\User\Infrastructure\Tenant;

use App\Client\Application\ClientMember\Query\ClientMemberQueryInterface;
use App\Client\Application\ClientMember\Query\Dto\ClientMemberDto;
use App\Client\Domain\ClientMember\ClientMember;
use App\SharedKernel\Domain\ValueObject\Id;
use App\User\Application\Tenant\Query\ActiveMembershipsQueryInterface;
use App\User\Application\Tenant\Query\Dto\ActiveMembershipDto;

final readonly class ActiveMembershipsQuery implements ActiveMembershipsQueryInterface
{
    public function __construct(
        private ClientMemberQueryInterface $clientMemberQuery,
    ) {
    }

    public function listForUser(Id $userId): array
    {
        $memberships = $this->clientMemberQuery->listByUser($userId);
        $activeMemberships = array_values(array_filter(
            $memberships,
            static fn (ClientMemberDto $membership): bool => ClientMember::STATUS_ACTIVE === $membership->status,
        ));

        return array_map(
            static function (ClientMemberDto $membership): ActiveMembershipDto {
                return new ActiveMembershipDto(
                    clientId: $membership->clientId,
                );
            },
            $activeMemberships,
        );
    }
}
