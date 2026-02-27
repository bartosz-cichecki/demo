<?php

declare(strict_types=1);

namespace App\SharedKernel\Infrastructure\Outside\Attribute;

#[\Attribute(\Attribute::TARGET_CLASS)]
final readonly class AsOutsideFor
{
    /**
     * @param class-string $interfaceFqcn The FQCN of the domain interface this outside implements
     */
    public function __construct(
        public string $interfaceFqcn,
    ) {
    }
}
