<?php

declare(strict_types=1);

namespace App\Client\Application\Client\Query\Dto;

final readonly class ClientDto
{
    public function __construct(
        public string $id,
        public string $name,
        public ?string $description,
        public string $status,
        public string $createdAt,
        public string $updatedAt,
    ) {
    }
}
