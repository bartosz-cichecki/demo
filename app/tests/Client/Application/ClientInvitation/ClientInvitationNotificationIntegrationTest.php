<?php

declare(strict_types=1);

namespace App\Tests\Client\Application\ClientInvitation;

use App\Client\Application\Client\Command\CreateClient\CreateClientCommand;
use App\Client\Application\ClientInvitation\Command\CreateClientInvitation\CreateClientInvitationCommand;
use App\Client\Application\IntegrationEvent\ClientInvitationCreatedIntegrationEvent;
use App\SharedKernel\Application\CommandBus\CommandBusInterface;
use App\SharedKernel\Domain\ValueObject\Email;
use App\SharedKernel\Domain\ValueObject\Id;
use App\User\Application\IntegrationEventSubscriber\SendClientInvitationNotificationSubscriber;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Process\Process;

final class ClientInvitationNotificationIntegrationTest extends KernelTestCase
{
    private CommandBusInterface $commandBus;
    private Connection $connection;
    private string $notificationLogPath;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $commandBus = $container->get(CommandBusInterface::class);
        $connection = $container->get(Connection::class);
        $notificationLogPath = $container->getParameter('app.user_notification_log_path');
        self::assertInstanceOf(CommandBusInterface::class, $commandBus);
        self::assertInstanceOf(Connection::class, $connection);
        self::assertIsString($notificationLogPath);
        $this->commandBus = $commandBus;
        $this->connection = $connection;
        $this->notificationLogPath = $notificationLogPath;
    }

    public function testRedeliveryAfterCrashDoesNotDuplicateNotification(): void
    {
        $clientId = Id::new();
        $invitationId = Id::new();
        $email = (string) Id::new() . '@example.com';
        $this->commandBus->dispatch(new CreateClientCommand($clientId, 'Notified Corp', null));
        $this->commandBus->dispatch(new CreateClientInvitationCommand($invitationId, $clientId, Email::fromString($email), 'user'));
        $this->runWorker();
        $outbox = $this->outboxRow($invitationId);
        self::assertCount(1, $this->notificationsFor($invitationId));

        // A worker that crashed after delivery but before recording consumption is restarted and repeats
        // the handler. The restart is a separate process, so deduplication cannot rely on process memory;
        // only the database and the notification file are shared.
        $this->connection->executeStatement('DELETE FROM shared.async_consumption WHERE event_id = :eventId', ['eventId' => $outbox['event_id']]);
        $this->connection->executeStatement('UPDATE shared.async_outbox SET processed_at = NULL, claimed_at = NULL, claimed_by = NULL WHERE event_id = :eventId', ['eventId' => $outbox['event_id']]);
        $this->runWorkerInSeparateProcess();

        self::assertNotNull($this->outboxRow($invitationId)['processed_at'], 'The restarted worker must process the event again.');

        self::assertCount(1, $this->notificationsFor($invitationId));
        self::assertSame(['processed'], $this->consumptionStatuses($outbox['event_id']));
        $notification = $this->notificationsFor($invitationId)[0];
        self::assertSame($email, $notification['email']);
        self::assertSame('Notified Corp', $notification['clientName']);
        self::assertSame('user', $notification['role']);
    }

    private function runWorker(): void
    {
        $kernel = self::$kernel;
        self::assertNotNull($kernel);
        $tester = new CommandTester((new Application($kernel))->find('app:process-outbox'));

        self::assertSame(0, $tester->execute(['--once' => true]), $tester->getDisplay());
    }

    private function runWorkerInSeparateProcess(): void
    {
        $kernel = self::$kernel;
        self::assertNotNull($kernel);
        $process = new Process(
            [\PHP_BINARY, $kernel->getProjectDir() . '/bin/console', 'app:process-outbox', '--once', '--env=' . $kernel->getEnvironment()],
            $kernel->getProjectDir(),
        );
        $process->setTimeout(60);
        $process->run();

        self::assertSame(0, $process->getExitCode(), $process->getOutput() . $process->getErrorOutput());
    }

    /**
     * @return array{event_id: string, processed_at: mixed}
     */
    private function outboxRow(Id $invitationId): array
    {
        $rows = $this->connection->fetchAllAssociative(
            "SELECT event_id, processed_at FROM shared.async_outbox WHERE event_name = :eventName AND payload ->> 'invitationId' = :invitationId",
            ['eventName' => ClientInvitationCreatedIntegrationEvent::class, 'invitationId' => (string) $invitationId],
        );
        self::assertCount(1, $rows);
        self::assertIsString($rows[0]['event_id']);

        return ['event_id' => $rows[0]['event_id'], 'processed_at' => $rows[0]['processed_at']];
    }

    /**
     * @return list<mixed>
     */
    private function consumptionStatuses(string $eventId): array
    {
        return $this->connection->fetchFirstColumn(
            'SELECT status FROM shared.async_consumption WHERE event_id = :eventId AND subscriber = :subscriber AND handler_method = :method',
            ['eventId' => $eventId, 'subscriber' => SendClientInvitationNotificationSubscriber::class, 'method' => 'onClientInvitationCreated'],
        );
    }

    /**
     * @return list<array<mixed>>
     */
    private function notificationsFor(Id $invitationId): array
    {
        if (!is_file($this->notificationLogPath)) {
            return [];
        }
        $lines = file($this->notificationLogPath, \FILE_IGNORE_NEW_LINES | \FILE_SKIP_EMPTY_LINES);
        self::assertNotFalse($lines);

        $notifications = [];
        foreach ($lines as $line) {
            $notification = json_decode($line, true, 512, \JSON_THROW_ON_ERROR);
            self::assertIsArray($notification);
            if (($notification['invitationId'] ?? null) === (string) $invitationId) {
                $notifications[] = $notification;
            }
        }

        return $notifications;
    }
}
