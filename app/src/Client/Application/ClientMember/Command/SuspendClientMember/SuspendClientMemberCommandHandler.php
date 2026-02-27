<?php

declare(strict_types=1);

namespace App\Client\Application\ClientMember\Command\SuspendClientMember;

use App\Client\Domain\ClientMember\Repository\ClientMemberRepositoryInterface;

final readonly class SuspendClientMemberCommandHandler
{
    public function __construct(
        private ClientMemberRepositoryInterface $clientMemberRepository,
    ) {
    }

    public function __invoke(SuspendClientMemberCommand $command): void
    {
        $member = $this->clientMemberRepository->getByClientAndUser($command->clientId, $command->userId);
        $member->suspend();
    }
}
