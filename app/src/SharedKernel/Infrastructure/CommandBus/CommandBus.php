<?php

declare(strict_types=1);

namespace App\SharedKernel\Infrastructure\CommandBus;

use App\SharedKernel\Application\CommandBus\CommandBusInterface;
use App\SharedKernel\Application\CommandBus\CommandInterface;
use App\SharedKernel\Application\CommandBus\CommandWithResultInterface;
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
        $this->execute($command);
    }

    /**
     * @template TResult of object
     *
     * @param CommandWithResultInterface<TResult> $command
     *
     * @return TResult
     */
    public function dispatchWithResult(CommandWithResultInterface $command): object
    {
        /** @var TResult $result */
        $result = $this->execute($command);

        return $result;
    }

    private function execute(CommandInterface $command): ?object
    {
        $this->em->beginTransaction();
        try {
            $handlerClass = $command::class . 'Handler';
            if (!$this->handlers->has($handlerClass)) {
                throw new \RuntimeException(\sprintf('Handler not found for command %s', $command::class));
            }

            /** @var callable(CommandInterface): mixed $handler */
            $handler = $this->handlers->get($handlerClass);
            $result = $handler($command);

            if ($command instanceof CommandWithResultInterface && !\is_object($result)) {
                throw new \UnexpectedValueException(\sprintf('Handler for command %s must return an object result.', $command::class));
            }

            $this->em->flush();

            while (null !== ($event = $this->domainEventsBuffer->poll())) {
                $this->eventLog->save($event);
                $this->eventBus->dispatch($event);
            }

            $this->em->commit();

            return \is_object($result) ? $result : null;
        } catch (\Throwable $exception) {
            $this->domainEventsBuffer->clear();
            $this->em->rollback();
            throw $exception;
        }
    }
}
