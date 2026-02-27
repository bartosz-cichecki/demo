<?php

declare(strict_types=1);

namespace App\SharedKernel\Infrastructure\Serializer;

use App\SharedKernel\Domain\ValueObject\Email;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

final class EmailNormalizer implements NormalizerInterface
{
    public function normalize(mixed $object, ?string $format = null, array $context = []): string
    {
        \assert($object instanceof Email);

        return (string) $object;
    }

    public function supportsNormalization(mixed $data, ?string $format = null, array $context = []): bool
    {
        return $data instanceof Email;
    }

    public function getSupportedTypes(?string $format): array
    {
        return [Email::class => true];
    }
}
