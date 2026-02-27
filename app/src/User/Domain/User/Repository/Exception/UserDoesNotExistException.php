<?php

declare(strict_types=1);

namespace App\User\Domain\User\Repository\Exception;

use App\SharedKernel\Domain\ValueObject\Id;

final class UserDoesNotExistException extends \Exception
{
    public function __construct(Id $id)
    {
        parent::__construct(\sprintf(
            'User with ID %s does not exist',
            $id,
        ));
    }
}
