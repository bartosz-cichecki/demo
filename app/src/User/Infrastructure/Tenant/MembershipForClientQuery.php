<?php

declare(strict_types=1);

namespace App\User\Infrastructure\Tenant;

use App\Client\Application\ClientMember\Query\ClientMemberQueryInterface;
use App\SharedKernel\Domain\ValueObject\Id;
use App\User\Application\Tenant\Query\Dto\MembershipDto;
use App\User\Application\Tenant\Query\MembershipForClientQueryInterface;

final readonly class MembershipForClientQuery implements MembershipForClientQueryInterface
{
    public function __construct(
        private ClientMemberQueryInterface $clientMemberQuery,
    ) {
    }

    public function findForUserAndClient(Id $userId, Id $clientId): ?MembershipDto
    {
        $membership = $this->clientMemberQuery->findByClientAndUser($clientId, $userId);
        if (null === $membership) {
            return null;
        }

        return new MembershipDto(
            isActive: $membership->isActive,
            isAdmin: $membership->isAdmin,
        );
    }
}
