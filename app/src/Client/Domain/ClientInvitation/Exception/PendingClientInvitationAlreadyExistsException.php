<?php

declare(strict_types=1);

namespace App\Client\Domain\ClientInvitation\Exception;

use App\SharedKernel\Domain\ValueObject\Id;

final class PendingClientInvitationAlreadyExistsException extends \Exception
{
    public function __construct(Id $clientId)
    {
        parent::__construct(\sprintf('A pending invitation for this email already exists in client %s', $clientId));
    }
}
