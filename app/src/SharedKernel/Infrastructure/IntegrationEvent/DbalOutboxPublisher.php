<?php

declare(strict_types=1);

namespace App\SharedKernel\Infrastructure\IntegrationEvent;

use App\SharedKernel\Application\IntegrationEvent\IntegrationEvent;
use App\SharedKernel\Application\IntegrationEvent\IntegrationEventPublisherInterface;
use App\SharedKernel\Domain\Clock\ClockInterface;
use App\SharedKernel\Domain\ValueObject\Id;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

final readonly class DbalOutboxPublisher implements IntegrationEventPublisherInterface
{
    public function __construct(
        private Connection $connection,
        private NormalizerInterface $normalizer,
        private ClockInterface $clock,
    ) {
    }

    public function publish(IntegrationEvent $event): void
    {
        $payload = $this->normalizer->normalize($event, 'json');
        if (!\is_array($payload)) {
            $payload = ['value' => $payload];
        }

        $this->connection->executeStatement(
            'INSERT INTO shared.async_outbox (event_id, event_name, payload, created_at) VALUES (:event_id, :event_name, :payload, :created_at)',
            [
                'event_id' => (string) Id::new(),
                'event_name' => $event::class,
                'payload' => $payload,
                'created_at' => $this->clock->now()->toStorageString(),
            ],
            [
                'event_id' => Types::GUID,
                'event_name' => Types::STRING,
                'payload' => Types::JSON,
                'created_at' => Types::STRING,
            ],
        );
    }
}
