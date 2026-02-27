<?php

declare(strict_types=1);

namespace App\Client\Application\ClientMember\Command\ProvisionClientMember;

use App\SharedKernel\Application\CommandBus\CommandInterface;
use App\SharedKernel\Domain\ValueObject\Id;

final readonly class ProvisionClientMemberCommand implements CommandInterface
{
    public function __construct(
        public Id $clientId,
        public string $email,
    ) {
    }
}
