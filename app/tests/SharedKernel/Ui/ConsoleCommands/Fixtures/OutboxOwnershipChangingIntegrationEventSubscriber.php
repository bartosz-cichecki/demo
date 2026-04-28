<?php

declare(strict_types=1);

namespace App\Tests\SharedKernel\Ui\ConsoleCommands\Fixtures;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;

final readonly class OutboxOwnershipChangingIntegrationEventSubscriber
{
    public function __construct(
        private Connection $connection,
    ) {
    }

    public function onNeutralOutboxOwnership(NeutralOutboxOwnershipEvent $event): void
    {
        $this->connection->executeStatement(
            "UPDATE shared.async_outbox SET claimed_by = :claimed_by WHERE event_name = :event_name AND payload ->> 'subject' = :subject",
            [
                'claimed_by' => 'external-worker',
                'event_name' => NeutralOutboxOwnershipEvent::class,
                'subject' => $event->subject,
            ],
            [
                'claimed_by' => Types::STRING,
                'event_name' => Types::STRING,
                'subject' => Types::STRING,
            ],
        );
    }
}
