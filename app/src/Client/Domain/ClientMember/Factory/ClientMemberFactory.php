<?php

declare(strict_types=1);

namespace App\Client\Domain\ClientMember\Factory;

use App\Client\Domain\ClientMember\ClientMember;
use App\Client\Domain\ClientMember\Outside\ClientMemberOutsideInterface;
use App\Client\Domain\ClientMember\Repository\Exception\ClientMemberAlreadyExistsException;
use App\SharedKernel\Domain\ValueObject\Id;

final readonly class ClientMemberFactory implements ClientMemberFactoryInterface
{
    public function __construct(
        private ClientMemberOutsideInterface $clientMemberOutside,
    ) {
    }

    /**
     * @param array<string> $roles
     */
    public function create(
        Id $id,
        Id $clientId,
        Id $userId,
        array $roles,
    ): ClientMember {
        if ($this->clientMemberOutside->membershipExists($clientId, $userId)) {
            throw new ClientMemberAlreadyExistsException($clientId, $userId);
        }

        return new ClientMember(
            $this->clientMemberOutside,
            $id,
            $clientId,
            $userId,
            $roles,
        );
    }
}
