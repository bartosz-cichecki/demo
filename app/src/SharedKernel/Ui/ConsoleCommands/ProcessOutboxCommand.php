<?php

declare(strict_types=1);

namespace App\SharedKernel\Ui\ConsoleCommands;

use App\SharedKernel\Application\IntegrationEvent\IntegrationEvent;
use App\SharedKernel\Domain\Clock\ClockInterface;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;

#[AsCommand(name: 'app:process-outbox')]
final class ProcessOutboxCommand extends Command
{
    private const int DEFAULT_LIMIT = 50;
    private const int DEFAULT_SLEEP_SECONDS = 5;
    private const int MAX_ATTEMPTS = 5;
    private const string LEASE_TTL = '-5 minutes';
    private const int LAST_ERROR_LIMIT = 500;

    /**
     * @param iterable<object> $subscribers
     */
    public function __construct(
        private readonly Connection $connection,
        private readonly DenormalizerInterface $denormalizer,
        private readonly ClockInterface $clock,
        #[AutowireIterator('app.integration_event_subscriber')]
        private readonly iterable $subscribers,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Maximum number of records claimed in one batch.', self::DEFAULT_LIMIT)
            ->addOption('once', null, InputOption::VALUE_NONE, 'Process one batch and exit.')
            ->addOption('sleep', null, InputOption::VALUE_REQUIRED, 'Seconds to sleep between empty polling iterations.', self::DEFAULT_SLEEP_SECONDS);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $limit = $this->positiveIntOption($input, 'limit');
        $sleepSeconds = $this->nonNegativeIntOption($input, 'sleep');
        $once = true === $input->getOption('once');

        while (true) {
            $rows = $this->claimPendingOutbox($limit);

            foreach ($rows as $row) {
                $this->processRow($row);
            }

            if ($once) {
                break;
            }

            if ([] === $rows && $sleepSeconds > 0) {
                sleep($sleepSeconds);
            }
        }

        return Command::SUCCESS;
    }

    private function positiveIntOption(InputInterface $input, string $name): int
    {
        $value = filter_var($input->getOption($name), \FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if (false === $value) {
            throw new \InvalidArgumentException(\sprintf('Option --%s must be a positive integer.', $name));
        }

        return $value;
    }

    private function nonNegativeIntOption(InputInterface $input, string $name): int
    {
        $value = filter_var($input->getOption($name), \FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        if (false === $value) {
            throw new \InvalidArgumentException(\sprintf('Option --%s must be a non-negative integer.', $name));
        }

        return $value;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function claimPendingOutbox(int $limit): array
    {
        $now = $this->nowStorageString();
        $leaseExpiredAt = $this->leaseExpiredAtStorageString();

        /** @var list<array<string, mixed>> $rows */
        $rows = $this->connection->fetchAllAssociative(
            'WITH batch AS MATERIALIZED (SELECT id FROM shared.async_outbox WHERE processed_at IS NULL AND attempts < :max_attempts AND (claimed_at IS NULL OR claimed_at < :lease_expired_at) ORDER BY id ASC LIMIT :limit FOR UPDATE SKIP LOCKED) UPDATE shared.async_outbox SET claimed_at = :claimed_at, claimed_by = :claimed_by, attempts = attempts + 1 FROM batch WHERE shared.async_outbox.id = batch.id RETURNING shared.async_outbox.id, shared.async_outbox.event_id, shared.async_outbox.event_name, shared.async_outbox.payload, shared.async_outbox.claimed_at',
            [
                'claimed_at' => $now,
                'claimed_by' => $this->workerId(),
                'max_attempts' => self::MAX_ATTEMPTS,
                'lease_expired_at' => $leaseExpiredAt,
                'limit' => $limit,
            ],
            [
                'claimed_at' => Types::STRING,
                'claimed_by' => Types::STRING,
                'max_attempts' => Types::INTEGER,
                'lease_expired_at' => Types::STRING,
                'limit' => Types::INTEGER,
            ],
        );

        return $rows;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function processRow(array $row): void
    {
        $outboxId = $this->stringValue($row['id'] ?? null);
        $eventId = $this->stringValue($row['event_id'] ?? null);

        try {
            $event = $this->denormalizeEvent(
                $this->stringValue($row['event_name'] ?? null),
                $this->payloadValue($row['payload'] ?? null),
            );
        } catch (\Throwable $exception) {
            $this->markOutboxFailed($outboxId, $this->shortError($exception));

            return;
        }

        foreach ($this->matchingHandlers($event) as $handler) {
            $claim = $this->claimHandler($eventId, $handler['subscriberName'], $handler['method']);

            if ('processed' === $claim) {
                continue;
            }

            if ('claimed' !== $claim) {
                $this->markOutboxFailed($outboxId, 'Async handler claim is owned by another worker.');

                return;
            }

            try {
                $handler['subscriber']->{$handler['method']}($event);
                if (!$this->markHandlerProcessed($eventId, $handler['subscriberName'], $handler['method'])) {
                    $this->markOutboxFailed($outboxId, 'Async handler claim ownership was lost before marking processed.');

                    return;
                }
            } catch (\Throwable $exception) {
                $this->releaseHandlerClaim($eventId, $handler['subscriberName'], $handler['method']);
                $this->markOutboxFailed($outboxId, $this->shortError($exception));

                return;
            }
        }

        $this->markOutboxProcessed($outboxId);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function denormalizeEvent(string $eventName, array $payload): IntegrationEvent
    {
        if (!class_exists($eventName)) {
            throw new \RuntimeException('Integration event class does not exist.');
        }

        if (!is_a($eventName, IntegrationEvent::class, true)) {
            throw new \RuntimeException('Integration event class does not implement the integration event contract.');
        }

        /** @var class-string<IntegrationEvent> $eventClass */
        $eventClass = $eventName;

        return $this->denormalizer->denormalize($payload, $eventClass, 'json');
    }

    /**
     * @return list<array{subscriber: object, subscriberName: class-string, method: string}>
     */
    private function matchingHandlers(IntegrationEvent $event): array
    {
        $handlers = [];

        foreach ($this->subscribers as $subscriber) {
            $reflection = new \ReflectionObject($subscriber);

            foreach ($reflection->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
                if (!str_starts_with($method->getName(), 'on')) {
                    continue;
                }

                if (1 !== $method->getNumberOfParameters()) {
                    continue;
                }

                $parameter = $method->getParameters()[0];
                $type = $parameter->getType();
                if (!$type instanceof \ReflectionNamedType || $type->isBuiltin()) {
                    continue;
                }

                $eventType = $type->getName();
                if (!$event instanceof $eventType) {
                    continue;
                }

                /** @var class-string $subscriberName */
                $subscriberName = $subscriber::class;
                $handlers[] = [
                    'subscriber' => $subscriber,
                    'subscriberName' => $subscriberName,
                    'method' => $method->getName(),
                ];
            }
        }

        return $handlers;
    }

    private function claimHandler(string $eventId, string $subscriber, string $handlerMethod): string
    {
        $now = $this->nowStorageString();
        $leaseExpiredAt = $this->leaseExpiredAtStorageString();

        $row = $this->connection->fetchAssociative(
            <<<'SQL'
                INSERT INTO shared.async_consumption (event_id, subscriber, handler_method, status, claimed_at, claimed_by)
                VALUES (:event_id, :subscriber, :handler_method, 'processing', :claimed_at, :claimed_by)
                ON CONFLICT (event_id, subscriber, handler_method)
                DO UPDATE SET status = 'processing',
                              claimed_at = :claimed_at,
                              claimed_by = :claimed_by,
                              processed_at = NULL
                WHERE shared.async_consumption.status <> 'processed'
                  AND (
                      shared.async_consumption.claimed_by = :claimed_by
                      OR shared.async_consumption.claimed_at IS NULL
                      OR shared.async_consumption.claimed_at < :lease_expired_at
                  )
                RETURNING status, claimed_by
                SQL,
            [
                'event_id' => $eventId,
                'subscriber' => $subscriber,
                'handler_method' => $handlerMethod,
                'claimed_at' => $now,
                'claimed_by' => $this->workerId(),
                'lease_expired_at' => $leaseExpiredAt,
            ],
            [
                'event_id' => Types::GUID,
                'subscriber' => Types::STRING,
                'handler_method' => Types::STRING,
                'claimed_at' => Types::STRING,
                'claimed_by' => Types::STRING,
                'lease_expired_at' => Types::STRING,
            ],
        );

        if (\is_array($row) && 'processing' === $row['status'] && $this->workerId() === $row['claimed_by']) {
            return 'claimed';
        }

        $existing = $this->connection->fetchAssociative(
            'SELECT status FROM shared.async_consumption WHERE event_id = :event_id AND subscriber = :subscriber AND handler_method = :handler_method',
            [
                'event_id' => $eventId,
                'subscriber' => $subscriber,
                'handler_method' => $handlerMethod,
            ],
            [
                'event_id' => Types::GUID,
                'subscriber' => Types::STRING,
                'handler_method' => Types::STRING,
            ],
        );

        if (\is_array($existing) && 'processed' === $existing['status']) {
            return 'processed';
        }

        return 'blocked';
    }

    private function markHandlerProcessed(string $eventId, string $subscriber, string $handlerMethod): bool
    {
        $affectedRows = $this->connection->executeStatement(
            <<<'SQL'
                UPDATE shared.async_consumption
                SET status = 'processed',
                    processed_at = :processed_at
                WHERE event_id = :event_id
                  AND subscriber = :subscriber
                  AND handler_method = :handler_method
                  AND claimed_by = :claimed_by
                  AND status = 'processing'
                SQL,
            [
                'processed_at' => $this->nowStorageString(),
                'event_id' => $eventId,
                'subscriber' => $subscriber,
                'handler_method' => $handlerMethod,
                'claimed_by' => $this->workerId(),
            ],
            [
                'processed_at' => Types::STRING,
                'event_id' => Types::GUID,
                'subscriber' => Types::STRING,
                'handler_method' => Types::STRING,
                'claimed_by' => Types::STRING,
            ],
        );

        return 1 === $affectedRows;
    }

    private function releaseHandlerClaim(string $eventId, string $subscriber, string $handlerMethod): void
    {
        $this->connection->executeStatement(
            <<<'SQL'
                DELETE FROM shared.async_consumption
                WHERE event_id = :event_id
                  AND subscriber = :subscriber
                  AND handler_method = :handler_method
                  AND claimed_by = :claimed_by
                  AND status = 'processing'
                SQL,
            [
                'event_id' => $eventId,
                'subscriber' => $subscriber,
                'handler_method' => $handlerMethod,
                'claimed_by' => $this->workerId(),
            ],
            [
                'event_id' => Types::GUID,
                'subscriber' => Types::STRING,
                'handler_method' => Types::STRING,
                'claimed_by' => Types::STRING,
            ],
        );
    }

    private function markOutboxProcessed(string $outboxId): void
    {
        $this->connection->executeStatement(
            <<<'SQL'
                UPDATE shared.async_outbox
                SET processed_at = :processed_at,
                    last_error = NULL
                WHERE id = :id
                  AND claimed_by = :claimed_by
                  AND processed_at IS NULL
                SQL,
            [
                'processed_at' => $this->nowStorageString(),
                'id' => $outboxId,
                'claimed_by' => $this->workerId(),
            ],
            [
                'processed_at' => Types::STRING,
                'id' => Types::INTEGER,
                'claimed_by' => Types::STRING,
            ],
        );
    }

    private function markOutboxFailed(string $outboxId, string $message): void
    {
        $this->connection->executeStatement(
            <<<'SQL'
                UPDATE shared.async_outbox
                SET last_error = :last_error,
                    claimed_at = NULL,
                    claimed_by = NULL
                WHERE id = :id
                  AND claimed_by = :claimed_by
                  AND processed_at IS NULL
                SQL,
            [
                'last_error' => $message,
                'id' => $outboxId,
                'claimed_by' => $this->workerId(),
            ],
            [
                'last_error' => Types::STRING,
                'id' => Types::INTEGER,
                'claimed_by' => Types::STRING,
            ],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function payloadValue(mixed $payload): array
    {
        if (\is_array($payload)) {
            return $this->stringKeyedArray($payload);
        }

        if (!\is_string($payload)) {
            throw new \RuntimeException('Integration event payload is not valid JSON.');
        }

        $decoded = json_decode($payload, true, flags: \JSON_THROW_ON_ERROR);
        if (!\is_array($decoded)) {
            throw new \RuntimeException('Integration event payload is not an object.');
        }

        return $this->stringKeyedArray($decoded);
    }

    /**
     * @param array<mixed, mixed> $payload
     *
     * @return array<string, mixed>
     */
    private function stringKeyedArray(array $payload): array
    {
        $result = [];

        foreach ($payload as $key => $value) {
            if (!\is_string($key)) {
                throw new \RuntimeException('Integration event payload is not an object.');
            }

            $result[$key] = $value;
        }

        return $result;
    }

    private function stringValue(mixed $value): string
    {
        if (!\is_scalar($value)) {
            throw new \RuntimeException('Outbox row contains an invalid scalar value.');
        }

        return (string) $value;
    }

    private function shortError(\Throwable $exception): string
    {
        $message = \sprintf('%s: %s', $exception::class, $exception->getMessage());

        return mb_substr($message, 0, self::LAST_ERROR_LIMIT);
    }

    private function nowStorageString(): string
    {
        return $this->clock->now()->toStorageString();
    }

    private function leaseExpiredAtStorageString(): string
    {
        return $this->clock->now()->value->modify(self::LEASE_TTL)->format('Y-m-d H:i:s');
    }

    private function workerId(): string
    {
        return gethostname() . ':' . getmypid();
    }
}
