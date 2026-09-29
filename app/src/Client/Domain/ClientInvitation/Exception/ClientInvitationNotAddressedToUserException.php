<?php

declare(strict_types=1);

namespace App\Client\Domain\ClientInvitation\Exception;

use App\SharedKernel\Domain\Exception\ResourceDoesNotExistException;
use App\SharedKernel\Domain\ValueObject\Id;

/**
 * For a user other than the invitee the invitation does not exist, so its existence is not revealed.
 */
final class ClientInvitationNotAddressedToUserException extends \Exception implements ResourceDoesNotExistException
{
    public function __construct(Id $id)
    {
        parent::__construct(\sprintf('Client invitation %s is not addressed to the current user', $id));
    }
}
