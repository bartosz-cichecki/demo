<?php

declare(strict_types=1);

namespace App\Client\Domain\Client\Repository;

use App\Client\Domain\Client\Client;
use App\Client\Domain\Client\Repository\Exception\ClientDoesNotExistException;
use App\SharedKernel\Domain\ValueObject\Id;

interface ClientRepositoryInterface
{
    public function create(Client $client): void;

    /**
     * @throws ClientDoesNotExistException
     */
    public function get(Id $id): Client;
}
