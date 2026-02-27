<?php

declare(strict_types=1);

namespace App\Client\Application\ClientMember\Command\ReplaceClientMemberRoles;

use App\SharedKernel\Application\CommandBus\CommandInterface;
use App\SharedKernel\Domain\ValueObject\Id;

final readonly class ReplaceClientMemberRolesCommand implements CommandInterface
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
