<?php

declare(strict_types=1);

namespace App\SharedKernel\Infrastructure\EventLog;

use App\SharedKernel\Application\EventLog\EventLogInterface;
use App\SharedKernel\Domain\Event\DomainEvent;
use App\SharedKernel\Domain\ValueObject\Id;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

final readonly class EventLog implements EventLogInterface
{
    public function __construct(
        private Connection $connection,
        private NormalizerInterface $normalizer,
    ) {
    }

    public function save(DomainEvent $event): void
    {
        $payload = $this->normalizer->normalize($event, 'json');
        if (!\is_array($payload)) {
            $payload = ['value' => $payload];
        }

        $this->connection->executeStatement(
            'INSERT INTO shared.event_log (event_id, event_name, occurred_at, payload) VALUES (:event_id, :event_name, :occurred_at, :payload)',
            [
                'event_id' => (string) Id::new(),
                'event_name' => $event::class,
                'occurred_at' => new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
                'payload' => $payload,
            ],
            [
                'event_id' => Types::GUID,
                'event_name' => Types::STRING,
                'occurred_at' => Types::DATETIME_IMMUTABLE,
                'payload' => Types::JSON,
            ],
        );
    }
}
