<?php

declare(strict_types=1);

namespace App\Client\Application\ClientInvitation\Query;

use App\Client\Application\ClientInvitation\Query\Dto\ClientInvitationDto;
use App\SharedKernel\Domain\ValueObject\Email;
use App\SharedKernel\Domain\ValueObject\Id;

interface ClientInvitationQueryInterface
{
    public function findById(Id $id): ?ClientInvitationDto;

    public function findPendingByClientAndEmail(Id $clientId, Email $email): ?ClientInvitationDto;

    /**
     * @return array<ClientInvitationDto>
     */
    public function listPendingByEmail(Email $email): array;
}
