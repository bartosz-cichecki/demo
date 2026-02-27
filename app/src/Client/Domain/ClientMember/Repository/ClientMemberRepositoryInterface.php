<?php

declare(strict_types=1);

namespace App\Client\Domain\ClientMember\Repository;

use App\Client\Domain\ClientMember\ClientMember;
use App\Client\Domain\ClientMember\Repository\Exception\ClientMemberDoesNotExistException;
use App\SharedKernel\Domain\ValueObject\Id;

interface ClientMemberRepositoryInterface
{
    public function create(ClientMember $member): void;

    /**
     * @throws ClientMemberDoesNotExistException
     */
    public function getByClientAndUser(Id $clientId, Id $userId): ClientMember;

    public function findByClientAndUser(Id $clientId, Id $userId): ?ClientMember;
}
