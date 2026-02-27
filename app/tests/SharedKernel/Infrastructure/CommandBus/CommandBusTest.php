<?php

declare(strict_types=1);

namespace App\Tests\SharedKernel\Infrastructure\CommandBus;

use App\SharedKernel\Application\CommandBus\CommandInterface;
use App\SharedKernel\Application\EventBus\EventBusInterface;
use App\SharedKernel\Application\EventLog\EventLogInterface;
use App\SharedKernel\Domain\Event\DomainEvent;
use App\SharedKernel\Domain\Event\InMemoryDomainEventsCollector;
use App\SharedKernel\Infrastructure\CommandBus\CommandBus;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

final class CommandBusTest extends TestCase
{
    private InMemoryDomainEventsCollector $buffer;
    private EntityManagerInterface $em;
    /** @var DomainEvent[] */
    private array $loggedEvents = [];
    /** @var DomainEvent[] */
    private array $dispatchedEvents = [];

    protected function setUp(): void
    {
        $this->buffer = new InMemoryDomainEventsCollector();
        $this->loggedEvents = [];
        $this->dispatchedEvents = [];
    }

    public function testBufferDoesNotLeakBetweenCommands(): void
    {
        // Given: CommandBus with a buffer that records events
        $commandBus = $this->createCommandBus([
            CommandThatRecordsEvent::class . 'Handler' => fn () => $this->buffer->record(new FakeDomainEvent()),
            CommandThatDoesNothing::class . 'Handler' => static fn () => null,
        ]);

        // When: dispatching a command that creates an event
        $commandBus->dispatch(new CommandThatRecordsEvent());

        // Then: event is logged and dispatched
        $this->assertCount(1, $this->loggedEvents);
        $this->assertCount(1, $this->dispatchedEvents);

        // Reset tracking arrays
        $this->loggedEvents = [];
        $this->dispatchedEvents = [];

        // When: dispatching a command that does NOT create events
        $commandBus->dispatch(new CommandThatDoesNothing());

        // Then: no events from the first command leak into the second
        $this->assertCount(0, $this->loggedEvents, 'Events leaked from previous command');
        $this->assertCount(0, $this->dispatchedEvents, 'Events leaked from previous command');
    }

    public function testBufferIsClearedAfterException(): void
    {
        // Given: CommandBus where the handler throws an exception after recording an event
        $commandBus = $this->createCommandBus([
            CommandThatRecordsEventAndThrows::class . 'Handler' => function (): void {
                $this->buffer->record(new FakeDomainEvent());
                throw new \RuntimeException('Handler failed');
            },
            CommandThatDoesNothing::class . 'Handler' => static fn () => null,
        ]);

        // When: dispatching a command that throws
        try {
            $commandBus->dispatch(new CommandThatRecordsEventAndThrows());
            $this->fail('Expected exception was not thrown');
        } catch (\RuntimeException $e) {
            $this->assertSame('Handler failed', $e->getMessage());
        }

        // Reset tracking arrays
        $this->loggedEvents = [];
        $this->dispatchedEvents = [];

        // When: dispatching a normal command after the failure
        $commandBus->dispatch(new CommandThatDoesNothing());

        // Then: buffer was cleared - no old events appear
        $this->assertCount(0, $this->loggedEvents, 'Buffer was not cleared after exception');
        $this->assertCount(0, $this->dispatchedEvents, 'Buffer was not cleared after exception');
    }

    public function testBufferIsClearedAfterEventBusException(): void
    {
        // Given: CommandBus where eventBus throws during dispatch
        $throwingEventBus = $this->createStub(EventBusInterface::class);
        $throwingEventBus->method('dispatch')
            ->willThrowException(new \RuntimeException('EventBus failed'));

        $commandBus = $this->createCommandBusWithEventBus(
            [
                CommandThatRecordsEvent::class . 'Handler' => fn () => $this->buffer->record(new FakeDomainEvent()),
            ],
            $throwingEventBus,
        );

        // When: dispatching a command where eventBus will throw
        try {
            $commandBus->dispatch(new CommandThatRecordsEvent());
            $this->fail('Expected exception was not thrown');
        } catch (\RuntimeException $e) {
            $this->assertSame('EventBus failed', $e->getMessage());
        }

        // Then: buffer was cleared by catch block (even though exception was thrown)
        $this->assertCount(0, $this->buffer->peek(), 'Buffer was not cleared after eventBus exception');
    }

    public function testDrainPicksUpEventsRecordedDuringDispatch(): void
    {
        // Given: EventBus where dispatching E1 causes E2 to be recorded (simulating saga/listener)
        $event1 = new FakeDomainEvent();
        $event2 = new FakeDomainEvent();

        $eventBus = $this->createStub(EventBusInterface::class);
        $eventBus->method('dispatch')
            ->willReturnCallback(function (DomainEvent $event) use ($event1, $event2): void {
                $this->dispatchedEvents[] = $event;
                if ($event === $event1) {
                    $this->buffer->record($event2);
                }
            });

        $commandBus = $this->createCommandBusWithEventBus(
            [
                CommandThatRecordsEvent::class . 'Handler' => fn () => $this->buffer->record($event1),
            ],
            $eventBus,
        );

        // When: dispatching the command
        $commandBus->dispatch(new CommandThatRecordsEvent());

        // Then: both E1 and E2 were dispatched in FIFO order
        $this->assertCount(2, $this->dispatchedEvents);
        $this->assertSame($event1, $this->dispatchedEvents[0]);
        $this->assertSame($event2, $this->dispatchedEvents[1]);

        // And: both events were logged
        $this->assertCount(2, $this->loggedEvents);
        $this->assertSame($event1, $this->loggedEvents[0]);
        $this->assertSame($event2, $this->loggedEvents[1]);

        // And: buffer is empty after dispatch (drained without explicit clear)
        $this->assertCount(0, $this->buffer->peek(), 'Buffer should be empty after drain');
    }

    /**
     * @param array<string, callable> $handlers
     */
    private function createCommandBus(array $handlers): CommandBus
    {
        return $this->createCommandBusWithEventBus($handlers, $this->createEventBus());
    }

    /**
     * @param array<string, callable> $handlers
     */
    private function createCommandBusWithEventBus(array $handlers, EventBusInterface $eventBus): CommandBus
    {
        $container = $this->createStub(ContainerInterface::class);
        $container->method('has')
            ->willReturnCallback(static fn (string $id) => isset($handlers[$id]));
        $container->method('get')
            ->willReturnCallback(static fn (string $id) => $handlers[$id]);

        $this->em = $this->createStub(EntityManagerInterface::class);

        return new CommandBus(
            $container,
            $this->em,
            $this->buffer,
            $eventBus,
            $this->createEventLog(),
        );
    }

    private function createEventLog(): EventLogInterface
    {
        $eventLog = $this->createStub(EventLogInterface::class);
        $eventLog->method('save')
            ->willReturnCallback(function (DomainEvent $event): void {
                $this->loggedEvents[] = $event;
            });

        return $eventLog;
    }

    private function createEventBus(): EventBusInterface
    {
        $eventBus = $this->createStub(EventBusInterface::class);
        $eventBus->method('dispatch')
            ->willReturnCallback(function (DomainEvent $event): void {
                $this->dispatchedEvents[] = $event;
            });

        return $eventBus;
    }
}

final class CommandThatRecordsEvent implements CommandInterface
{
}

final class CommandThatDoesNothing implements CommandInterface
{
}

final class CommandThatRecordsEventAndThrows implements CommandInterface
{
}

final class FakeDomainEvent implements DomainEvent
{
}
