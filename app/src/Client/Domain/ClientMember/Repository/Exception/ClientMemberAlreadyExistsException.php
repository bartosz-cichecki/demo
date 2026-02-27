<?php

declare(strict_types=1);

namespace App\Client\Domain\ClientMember\Repository\Exception;

use App\SharedKernel\Domain\ValueObject\Id;

final class ClientMemberAlreadyExistsException extends \Exception
{
    public function __construct(Id $clientId, Id $userId)
    {
        parent::__construct(\sprintf(
            'Client member for client %s and user %s already exists',
            $clientId,
            $userId,
        ));
    }
}
