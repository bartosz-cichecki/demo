<?php

declare(strict_types=1);

namespace App\Client\Application\Client\Query;

use App\Client\Application\Client\Query\Dto\ClientDto;
use App\SharedKernel\Domain\ValueObject\Id;

interface ClientQueryInterface
{
    public function findById(Id $id): ?ClientDto;
}
