<?php

declare(strict_types=1);

namespace App\Client\Application\ClientInvitation\Command\RevokeClientAdminInvitation;

use App\SharedKernel\Application\CommandBus\CommandInterface;
use App\SharedKernel\Domain\ValueObject\Email;
use App\SharedKernel\Domain\ValueObject\Id;

final readonly class RevokeClientAdminInvitationCommand implements CommandInterface
{
    public function __construct(
        public Id $clientId,
        public Email $email,
    ) {
    }
}
