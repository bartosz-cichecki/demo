<?php

declare(strict_types=1);

namespace App\Client\Application\ClientMember\Query;

use App\Client\Application\ClientMember\Query\Dto\ClientMemberDto;
use App\SharedKernel\Domain\ValueObject\Id;

interface ClientMemberQueryInterface
{
    public function findById(Id $id): ?ClientMemberDto;

    public function findByClientAndUser(Id $clientId, Id $userId): ?ClientMemberDto;

    /**
     * @return array<ClientMemberDto>
     */
    public function listByClient(Id $clientId): array;

    /**
     * @return array<ClientMemberDto>
     */
    public function listByUser(Id $userId): array;
}
