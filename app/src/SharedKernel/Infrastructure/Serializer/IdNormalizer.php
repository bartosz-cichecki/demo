<?php

declare(strict_types=1);

namespace App\SharedKernel\Infrastructure\Serializer;

use App\SharedKernel\Domain\ValueObject\Id;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

final class IdNormalizer implements NormalizerInterface
{
    public function normalize(mixed $object, ?string $format = null, array $context = []): string
    {
        \assert($object instanceof Id);

        return (string) $object;
    }

    public function supportsNormalization(mixed $data, ?string $format = null, array $context = []): bool
    {
        return $data instanceof Id;
    }

    public function getSupportedTypes(?string $format): array
    {
        return [Id::class => true];
    }
}
