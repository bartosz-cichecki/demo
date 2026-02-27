<?php

declare(strict_types=1);

namespace App\User\Application\Tenant\Service;

final readonly class ActiveClientIdResolution
{
    private function __construct(
        public bool $allowed,
        public ?string $clientId,
        public bool $warning,
    ) {
    }

    public static function deny(): self
    {
        return new self(
            allowed: false,
            clientId: null,
            warning: false,
        );
    }

    public static function allow(string $clientId, bool $warning): self
    {
        return new self(
            allowed: true,
            clientId: $clientId,
            warning: $warning,
        );
    }
}
