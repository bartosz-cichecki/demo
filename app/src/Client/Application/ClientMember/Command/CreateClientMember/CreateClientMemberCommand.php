<?php

declare(strict_types=1);

namespace App\Client\Application\ClientMember\Command\CreateClientMember;

use App\SharedKernel\Application\CommandBus\CommandInterface;
use App\SharedKernel\Domain\ValueObject\Id;

final readonly class CreateClientMemberCommand implements CommandInterface
{
    /**
     * @param array<string> $roles
     */
    public function __construct(
        public Id $clientId,
        public Id $userId,
        public array $roles,
    ) {
    }
}
