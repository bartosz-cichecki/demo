<?php

declare(strict_types=1);

namespace App\SharedKernel\Infrastructure\EventBus;

use App\SharedKernel\Application\EventBus\EventBusInterface;
use App\SharedKernel\Domain\Event\DomainEvent;

/**
 * In-memory event bus — dispatches domain events to *Saga subscribers.
 *
 * Discovery: iterates sagas, finds public on*() methods whose single
 * parameter type-hint matches the dispatched event class.
 *
 * IMPORTANT: Subscribers MUST NOT perform any I/O. All persistence
 * is handled by EventLog in the CommandBus transaction.
 */
final readonly class EventBus implements EventBusInterface
{
    /** @var iterable<object> */
    private iterable $sagas;

    /**
     * @param iterable<object> $sagas
     */
    public function __construct(iterable $sagas)
    {
        $this->sagas = $sagas;
    }

    public function dispatch(DomainEvent $event): void
    {
        foreach ($this->sagas as $saga) {
            $ref = new \ReflectionClass($saga);
            foreach ($ref->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
                if (!str_starts_with($method->getName(), 'on')) {
                    continue;
                }
                $params = $method->getParameters();
                if (1 !== \count($params)) {
                    continue;
                }
                $type = $params[0]->getType();
                if (!$type instanceof \ReflectionNamedType || $type->isBuiltin()) {
                    continue;
                }
                if ($type->getName() === $event::class) {
                    $method->invoke($saga, $event);
                }
            }
        }
    }
}
