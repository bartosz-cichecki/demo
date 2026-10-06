<?php

declare(strict_types=1);

namespace App\Client\Application\ClientInvitation\Command\AcceptClientInvitation;

use App\Client\Application\ClientInvitation\Query\ClientInvitationQueryInterface;
use App\Client\Domain\ClientInvitation\Exception\ClientInvitationNotAddressedToUserException;
use App\Client\Domain\ClientInvitation\Exception\ClientInvitationNotPendingException;
use App\Client\Domain\ClientInvitation\Repository\ClientInvitationRepositoryInterface;
use App\Client\Domain\ClientInvitation\Repository\Exception\ClientInvitationDoesNotExistException;
use App\Client\Domain\ClientMember\Factory\ClientMemberFactoryInterface;
use App\Client\Domain\ClientMember\Repository\ClientMemberRepositoryInterface;
use App\Client\Domain\ClientMember\Repository\Exception\ClientMemberAlreadyExistsException;
use App\SharedKernel\Domain\ValueObject\Id;

final readonly class AcceptClientInvitationCommandHandler
{
    public function __construct(
        private ClientInvitationRepositoryInterface $clientInvitationRepository,
        private ClientMemberFactoryInterface $clientMemberFactory,
        private ClientMemberRepositoryInterface $clientMemberRepository,
        private ClientInvitationQueryInterface $clientInvitationQuery,
    ) {
    }

    /**
     * @throws ClientInvitationDoesNotExistException
     * @throws ClientInvitationNotAddressedToUserException
     * @throws ClientInvitationNotPendingException
     * @throws ClientMemberAlreadyExistsException
     */
    public function __invoke(AcceptClientInvitationCommand $command): void
    {
        $invitation = $this->clientInvitationRepository->get($command->invitationId);
        $invitation->accept($command->userId);

        // Read immutable membership data while the invitation row remains locked.
        $invitationData = $this->clientInvitationQuery->findById($command->invitationId)
            ?? throw new ClientInvitationDoesNotExistException($command->invitationId);
        $member = $this->clientMemberFactory->create(
            Id::new(),
            new Id($invitationData->clientId),
            $command->userId,
            [$invitationData->role],
        );
        $this->clientMemberRepository->create($member);
    }
}
