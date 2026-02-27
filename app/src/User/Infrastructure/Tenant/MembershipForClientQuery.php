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
            roles: $this->normalizeRoles($membership->roles),
            status: $membership->status,
        );
    }

    /**
     * @param array<string> $roles
     *
     * @return array<string>
     */
    private function normalizeRoles(array $roles): array
    {
        $roles = array_values(array_unique($roles));
        sort($roles);

        return $roles;
    }
}
