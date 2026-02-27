<?php

declare(strict_types=1);

namespace App\SharedKernel\Infrastructure\CommandBus;

use App\SharedKernel\Application\CommandBus\CommandBusInterface;
use App\SharedKernel\Application\CommandBus\CommandInterface;
use App\SharedKernel\Application\EventBus\EventBusInterface;
use App\SharedKernel\Application\EventLog\EventLogInterface;
use App\SharedKernel\Domain\Event\DomainEventsBuffer;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Container\ContainerInterface;

final readonly class CommandBus implements CommandBusInterface
{
    public function __construct(
        private ContainerInterface $handlers,
        private EntityManagerInterface $em,
        private DomainEventsBuffer $domainEventsBuffer,
        private EventBusInterface $eventBus,
        private EventLogInterface $eventLog,
    ) {
    }

    public function dispatch(CommandInterface $command): void
    {
        $this->em->beginTransaction();
        try {
            $handlerClass = $command::class . 'Handler';
            if (!$this->handlers->has($handlerClass)) {
                throw new \RuntimeException(\sprintf('Handler not found for command %s', $command::class));
            }

            /** @var callable(CommandInterface): void $handler */
            $handler = $this->handlers->get($handlerClass);
            $handler($command);

            $this->em->flush();

            while (null !== ($event = $this->domainEventsBuffer->poll())) {
                $this->eventLog->save($event);
                $this->eventBus->dispatch($event);
            }

            $this->em->commit();
        } catch (\Throwable $exception) {
            $this->domainEventsBuffer->clear();
            $this->em->rollback();
            throw $exception;
        }
    }
}
