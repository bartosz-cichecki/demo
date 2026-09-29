<?php

declare(strict_types=1);

namespace App\Client\Domain\ClientInvitation\Factory;

use App\Client\Domain\ClientInvitation\ClientInvitation;
use App\Client\Domain\ClientInvitation\Exception\ClientInvitationRoleNotAllowedException;
use App\Client\Domain\ClientInvitation\Exception\InviteeAlreadyMemberException;
use App\Client\Domain\ClientInvitation\Exception\PendingClientInvitationAlreadyExistsException;
use App\Client\Domain\ClientInvitation\Outside\ClientInvitationOutsideInterface;
use App\Client\Domain\ClientMember\ClientMember;
use App\SharedKernel\Domain\ValueObject\Email;
use App\SharedKernel\Domain\ValueObject\Id;

final readonly class ClientInvitationFactory implements ClientInvitationFactoryInterface
{
    public function __construct(
        private ClientInvitationOutsideInterface $clientInvitationOutside,
    ) {
    }

    public function createByClientAdmin(
        Id $id,
        Id $clientId,
        Email $email,
        string $role,
    ): ClientInvitation {
        // Role admin is granted by invitation only from the platform.
        if (ClientMember::ROLE_USER !== $role) {
            throw new ClientInvitationRoleNotAllowedException($role);
        }

        // Backed by a partial unique index on (client_id, email) for pending invitations.
        if ($this->clientInvitationOutside->pendingInvitationExists($clientId, $email)) {
            throw new PendingClientInvitationAlreadyExistsException($clientId);
        }

        if ($this->clientInvitationOutside->membershipExists($clientId, $email)) {
            throw new InviteeAlreadyMemberException($clientId);
        }

        return new ClientInvitation(
            $this->clientInvitationOutside,
            $id,
            $clientId,
            $email,
            $role,
        );
    }
}
