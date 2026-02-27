<?php

declare(strict_types=1);

namespace App\SharedKernel\Ui\Input;

use Symfony\Component\Validator\Constraints\Collection;

interface Input
{
    /**
     * @param array<string, mixed> $payload
     */
    public static function create(array $payload): self;

    public static function getSchema(): Collection;
}
