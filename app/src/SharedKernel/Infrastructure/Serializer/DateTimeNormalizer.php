<?php

declare(strict_types=1);

namespace App\SharedKernel\Infrastructure\Serializer;

use App\SharedKernel\Domain\ValueObject\DateTime;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

final class DateTimeNormalizer implements NormalizerInterface
{
    public function normalize(mixed $object, ?string $format = null, array $context = []): string
    {
        \assert($object instanceof DateTime);

        return $object->value->format(\DATE_ATOM);
    }

    public function supportsNormalization(mixed $data, ?string $format = null, array $context = []): bool
    {
        return $data instanceof DateTime;
    }

    public function getSupportedTypes(?string $format): array
    {
        return [DateTime::class => true];
    }
}
