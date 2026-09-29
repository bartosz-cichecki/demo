<?php

declare(strict_types=1);

namespace App\Client\Domain\ClientInvitation\Repository;

use App\Client\Domain\ClientInvitation\ClientInvitation;
use App\Client\Domain\ClientInvitation\Repository\Exception\ClientInvitationDoesNotExistException;
use App\SharedKernel\Domain\ValueObject\Id;

interface ClientInvitationRepositoryInterface
{
    public function create(ClientInvitation $invitation): void;

    /**
     * @throws ClientInvitationDoesNotExistException
     */
    public function get(Id $id): ClientInvitation;

    /**
     * @throws ClientInvitationDoesNotExistException when the invitation is absent or belongs to another client
     */
    public function getForClient(Id $id, Id $clientId): ClientInvitation;
}
