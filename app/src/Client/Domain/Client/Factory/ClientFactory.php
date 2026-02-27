<?php

declare(strict_types=1);

namespace App\Client\Domain\Client\Factory;

use App\Client\Domain\Client\Client;
use App\Client\Domain\Client\Outside\ClientOutsideInterface;
use App\SharedKernel\Domain\ValueObject\Id;

final readonly class ClientFactory implements ClientFactoryInterface
{
    public function __construct(
        private ClientOutsideInterface $clientOutside,
    ) {
    }

    public function create(
        Id $id,
        string $name,
        ?string $description,
    ): Client {
        return new Client(
            $this->clientOutside,
            $id,
            $name,
            $description,
        );
    }
}
