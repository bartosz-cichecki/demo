<?php

declare(strict_types=1);

namespace App\Client\Application\ClientMember\Command\CreateClientMember;

use App\Client\Domain\ClientMember\Factory\ClientMemberFactoryInterface;
use App\Client\Domain\ClientMember\Repository\ClientMemberRepositoryInterface;
use App\Client\Domain\ClientMember\Repository\Exception\ClientMemberAlreadyExistsException;
use App\SharedKernel\Domain\ValueObject\Id;

final readonly class CreateClientMemberCommandHandler
{
    public function __construct(
        private ClientMemberFactoryInterface $clientMemberFactory,
        private ClientMemberRepositoryInterface $clientMemberRepository,
    ) {
    }

    /**
     * @throws ClientMemberAlreadyExistsException
     */
    public function __invoke(CreateClientMemberCommand $command): void
    {
        $member = $this->clientMemberFactory->create(
            Id::new(),
            $command->clientId,
            $command->userId,
            $command->roles,
        );
        $this->clientMemberRepository->create($member);
    }
}
