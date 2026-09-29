<?php

declare(strict_types=1);

namespace App\Client\Domain\ClientInvitation\Repository\Exception;

use App\SharedKernel\Domain\Exception\ResourceDoesNotExistException;
use App\SharedKernel\Domain\ValueObject\Id;

final class ClientInvitationDoesNotExistException extends \Exception implements ResourceDoesNotExistException
{
    public function __construct(Id $id)
    {
        parent::__construct(\sprintf('Client invitation with ID %s does not exist', $id));
    }
}
