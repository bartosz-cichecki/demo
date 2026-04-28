<?php

declare(strict_types=1);

namespace App\Tests\SharedKernel\Ui\ConsoleCommands\Fixtures;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;

final readonly class ConsumptionOwnershipChangingIntegrationEventSubscriber
{
    public function __construct(
        private Connection $connection,
    ) {
    }

    public function onNeutralConsumptionOwnership(NeutralConsumptionOwnershipEvent $event): void
    {
        $this->connection->executeStatement(
            <<<'SQL'
                UPDATE shared.async_consumption
                SET claimed_by = :claimed_by
                WHERE subscriber = :subscriber
                  AND handler_method = :handler_method
                  AND event_id = (
                      SELECT event_id
                      FROM shared.async_outbox
                      WHERE event_name = :event_name
                        AND payload ->> 'subject' = :subject
                      ORDER BY id DESC
                      LIMIT 1
                  )
                SQL,
            [
                'claimed_by' => 'external-worker',
                'subscriber' => self::class,
                'handler_method' => 'onNeutralConsumptionOwnership',
                'event_name' => NeutralConsumptionOwnershipEvent::class,
                'subject' => $event->subject,
            ],
            [
                'claimed_by' => Types::STRING,
                'subscriber' => Types::STRING,
                'handler_method' => Types::STRING,
                'event_name' => Types::STRING,
                'subject' => Types::STRING,
            ],
        );
    }
}
