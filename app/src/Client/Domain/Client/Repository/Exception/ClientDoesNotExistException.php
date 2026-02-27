<?php

declare(strict_types=1);

namespace App\Client\Domain\Client\Repository\Exception;

use App\SharedKernel\Domain\ValueObject\Id;

final class ClientDoesNotExistException extends \Exception
{
    public function __construct(Id $id)
    {
        parent::__construct(\sprintf(
            'Client with ID %s does not exist',
            $id,
        ));
    }
}
