<?php

declare(strict_types=1);

namespace App\Client\Application\ClientMember\Command\ProvisionClientMember;

use App\Client\Application\ClientMember\Port\UserProvisioningServiceInterface;
use App\Client\Domain\ClientMember\ClientMember;
use App\Client\Domain\ClientMember\Factory\ClientMemberFactoryInterface;
use App\Client\Domain\ClientMember\Repository\ClientMemberRepositoryInterface;
use App\Client\Domain\ClientMember\Repository\Exception\ClientMemberAlreadyExistsException;
use App\SharedKernel\Domain\ValueObject\Id;

final readonly class ProvisionClientMemberCommandHandler
{
    public function __construct(
        private UserProvisioningServiceInterface $userProvisioning,
        private ClientMemberFactoryInterface $clientMemberFactory,
        private ClientMemberRepositoryInterface $clientMemberRepository,
    ) {
    }

    /**
     * @throws ClientMemberAlreadyExistsException
     */
    public function __invoke(ProvisionClientMemberCommand $command): void
    {
        $userId = $this->userProvisioning->ensureUserExists($command->email);

        $existing = $this->clientMemberRepository->findByClientAndUser($command->clientId, $userId);
        if (null !== $existing) {
            throw new ClientMemberAlreadyExistsException($command->clientId, $userId);
        }

        $member = $this->clientMemberFactory->create(
            Id::new(),
            $command->clientId,
            $userId,
            [ClientMember::ROLE_USER],
        );
        $this->clientMemberRepository->create($member);
    }
}
