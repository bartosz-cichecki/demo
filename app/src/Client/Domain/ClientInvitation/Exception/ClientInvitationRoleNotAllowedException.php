<?php

declare(strict_types=1);

namespace App\Client\Domain\ClientInvitation\Exception;

final class ClientInvitationRoleNotAllowedException extends \Exception
{
    public function __construct(string $role)
    {
        parent::__construct(\sprintf('Client admin cannot manage invitations with role %s', $role));
    }
}
