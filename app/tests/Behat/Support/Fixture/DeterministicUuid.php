<?php

declare(strict_types=1);

namespace App\Tests\Behat\Support\Fixture;

use App\SharedKernel\Domain\ValueObject\Id;

final class DeterministicUuid
{
    public static function fromAlias(string $alias): Id
    {
        $hash = md5($alias);

        $uuid = \sprintf(
            '%s-%s-%s-%s-%s',
            substr($hash, 0, 8),
            substr($hash, 8, 4),
            '4' . substr($hash, 13, 3),
            dechex(8 + (hexdec(substr($hash, 16, 1)) & 3)) . substr($hash, 17, 3),
            substr($hash, 20, 12),
        );

        return new Id($uuid);
    }
}
