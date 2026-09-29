<?php

declare(strict_types=1);

namespace App\Client\Domain\ClientInvitation\Exception;

use App\SharedKernel\Domain\ValueObject\Id;

final class InviteeAlreadyMemberException extends \Exception
{
    public function __construct(Id $clientId)
    {
        parent::__construct(\sprintf('The invited user already has a membership in client %s', $clientId));
    }
}
