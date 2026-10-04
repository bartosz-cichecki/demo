<?php

declare(strict_types=1);

namespace App\Client\Domain\ClientInvitation\Repository\Exception;

use App\SharedKernel\Domain\Exception\ResourceDoesNotExistException;
use App\SharedKernel\Domain\ValueObject\Id;

final class PendingClientInvitationDoesNotExistException extends \Exception implements ResourceDoesNotExistException
{
    public function __construct(Id $clientId)
    {
        parent::__construct(\sprintf('Pending invitation for the requested email in client %s does not exist', $clientId));
    }
}
