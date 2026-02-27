<?php

declare(strict_types=1);

namespace App\Client\Application\ClientMember\Command\UnsuspendClientMember;

use App\Client\Domain\ClientMember\Repository\ClientMemberRepositoryInterface;

final readonly class UnsuspendClientMemberCommandHandler
{
    public function __construct(
        private ClientMemberRepositoryInterface $clientMemberRepository,
    ) {
    }

    public function __invoke(UnsuspendClientMemberCommand $command): void
    {
        $member = $this->clientMemberRepository->getByClientAndUser($command->clientId, $command->userId);
        $member->unsuspend();
    }
}
