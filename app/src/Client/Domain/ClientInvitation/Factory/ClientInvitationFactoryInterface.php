<?php

declare(strict_types=1);

namespace App\Client\Domain\ClientInvitation\Factory;

use App\Client\Domain\ClientInvitation\ClientInvitation;
use App\Client\Domain\ClientInvitation\Exception\ClientInvitationRoleNotAllowedException;
use App\Client\Domain\ClientInvitation\Exception\InviteeAlreadyMemberException;
use App\Client\Domain\ClientInvitation\Exception\PendingClientInvitationAlreadyExistsException;
use App\SharedKernel\Domain\ValueObject\Email;
use App\SharedKernel\Domain\ValueObject\Id;

interface ClientInvitationFactoryInterface
{
    /**
     * @throws ClientInvitationRoleNotAllowedException
     * @throws PendingClientInvitationAlreadyExistsException
     * @throws InviteeAlreadyMemberException
     */
    public function createByClientAdmin(
        Id $id,
        Id $clientId,
        Email $email,
        string $role,
    ): ClientInvitation;
}
