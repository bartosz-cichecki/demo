<?php

declare(strict_types=1);

namespace App\Client\Domain\ClientInvitation\Exception;

use App\SharedKernel\Domain\ValueObject\Id;

final class ClientInvitationNotPendingException extends \Exception
{
    public function __construct(Id $id)
    {
        parent::__construct(\sprintf('Client invitation %s is not pending', $id));
    }
}
