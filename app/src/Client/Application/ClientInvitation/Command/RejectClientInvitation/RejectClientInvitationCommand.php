<?php

declare(strict_types=1);

namespace App\Client\Application\ClientInvitation\Command\RejectClientInvitation;

use App\SharedKernel\Application\CommandBus\CommandInterface;
use App\SharedKernel\Domain\ValueObject\Id;

final readonly class RejectClientInvitationCommand implements CommandInterface
{
    public function __construct(
        public Id $invitationId,
        public Id $userId,
    ) {
    }
}
