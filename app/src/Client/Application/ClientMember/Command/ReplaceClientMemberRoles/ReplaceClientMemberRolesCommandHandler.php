<?php

declare(strict_types=1);

namespace App\Client\Application\ClientMember\Command\ReplaceClientMemberRoles;

use App\Client\Domain\ClientMember\Repository\ClientMemberRepositoryInterface;

final readonly class ReplaceClientMemberRolesCommandHandler
{
    public function __construct(
        private ClientMemberRepositoryInterface $clientMemberRepository,
    ) {
    }

    public function __invoke(ReplaceClientMemberRolesCommand $command): void
    {
        $member = $this->clientMemberRepository->getByClientAndUser($command->clientId, $command->userId);
        $member->replaceRoles($command->roles);
    }
}
