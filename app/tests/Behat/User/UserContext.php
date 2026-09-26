<?php

declare(strict_types=1);

namespace App\Tests\Behat\User;

use App\SharedKernel\Application\CommandBus\CommandBusInterface;
use App\SharedKernel\Domain\Clock\MutableClock;
use App\SharedKernel\Domain\ValueObject\Email;
use App\SharedKernel\Domain\ValueObject\Id;
use App\Tests\Behat\Support\Fixture\FixtureRegistry;
use App\Tests\Behat\Support\Fixture\UserFixture;
use App\User\Application\IntegrationEvent\UserRegisteredIntegrationEvent;
use App\User\Application\OtpChallenge\Command\RequestOtp\RequestOtpCommand;
use App\User\Application\OtpChallenge\Command\VerifyOtp\VerifyOtpCommand;
use App\User\Application\OtpChallenge\Query\OtpChallengeQueryInterface;
use App\User\Application\User\Command\UpsertUserByEmail\UpsertUserByEmailCommand;
use App\User\Application\User\Query\UserQueryInterface;
use Behat\Behat\Context\Context;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Assert;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpKernel\KernelInterface;

final class UserContext implements Context
{
    private const int OTP_MAX_ATTEMPTS = 5;
    private const string OTP_COOLDOWN_ELAPSED_MODIFIER = '+61 seconds';

    private ?string $deliveredOtpCode = null;

    public function __construct(
        private readonly KernelBrowser $client,
        private readonly Connection $connection,
        private readonly UserQueryInterface $userQuery,
        private readonly OtpChallengeQueryInterface $otpChallengeQuery,
        private readonly FixtureRegistry $registry,
        private readonly CommandBusInterface $commandBus,
        private readonly MutableClock $clock,
        private readonly KernelInterface $kernel,
        private readonly string $userNotificationLogPath,
        private readonly string $otpMailboxPath,
    ) {
    }

    // ========================================
    // When: HTTP endpoints only
    // ========================================

    /**
     * @When I request OTP for email :email
     */
    public function iRequestOtpForEmail(string $email): void
    {
        $this->iRequestOtpForEmailFromIp($email, '127.0.0.1');
    }

    /**
     * @When I request OTP for email :email from IP :ipAddress
     */
    public function iRequestOtpForEmailFromIp(string $email, string $ipAddress): void
    {
        $this->client->request(
            'POST',
            '/api/auth/otp/request',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'REMOTE_ADDR' => $ipAddress],
            json_encode(['email' => $email], \JSON_THROW_ON_ERROR),
        );

        $response = $this->client->getResponse();
        Assert::assertSame(200, $response->getStatusCode());
        Assert::assertSame('{"ok":true}', $response->getContent());
    }

    /**
     * @Then I can read the delivered OTP for :email from the demo mailbox
     */
    public function iCanReadTheDeliveredOtpFromTheDemoMailbox(string $email): void
    {
        $messages = array_values(array_filter(
            $this->otpMessages(),
            static fn (array $message): bool => $message['email'] === (string) Email::fromString($email),
        ));
        Assert::assertNotEmpty($messages);
        $this->deliveredOtpCode = $messages[\count($messages) - 1]['code'];
        Assert::assertMatchesRegularExpression('/^[0-9]{6}$/D', $this->deliveredOtpCode);

        $hash = $this->connection->fetchOne(
            'SELECT code_hash FROM "user".otp_challenges WHERE email = :email ORDER BY last_sent_at DESC LIMIT 1',
            ['email' => (string) Email::fromString($email)],
        );
        Assert::assertIsString($hash);
        Assert::assertNotSame($this->deliveredOtpCode, $hash);
        Assert::assertTrue(password_verify($this->deliveredOtpCode, $hash));
    }

    /**
     * @When I verify OTP for email :email with the delivered code
     */
    public function iVerifyOtpForEmailWithTheDeliveredCode(string $email): void
    {
        Assert::assertNotNull($this->deliveredOtpCode);
        $this->iVerifyOtpForEmailWithCode($email, $this->deliveredOtpCode);
    }

    /**
     * @Then the demo mailbox should contain :count OTP messages
     */
    public function theDemoMailboxShouldContainOtpMessages(int $count): void
    {
        Assert::assertCount($count, $this->otpMessages());
    }

    /**
     * @Then there should be :count OTP challenges for :email
     */
    public function thereShouldBeOtpChallengesFor(int $count, string $email): void
    {
        $actual = $this->connection->fetchOne(
            'SELECT COUNT(*) FROM "user".otp_challenges WHERE email = :email',
            ['email' => (string) Email::fromString($email)],
        );
        Assert::assertSame($count, $this->intValue($actual));
    }

    /**
     * @Then there should be :count OTP challenges in total
     */
    public function thereShouldBeOtpChallengesInTotal(int $count): void
    {
        $actual = $this->connection->fetchOne('SELECT COUNT(*) FROM "user".otp_challenges');
        Assert::assertSame($count, $this->intValue($actual));
    }

    /**
     * @When I verify OTP for email :email with code :code
     */
    public function iVerifyOtpForEmailWithCode(string $email, string $code): void
    {
        $this->client->request(
            'POST',
            '/api/auth/otp/verify',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['email' => $email, 'code' => $code], \JSON_THROW_ON_ERROR),
        );

        $response = $this->client->getResponse();
        Assert::assertSame(200, $response->getStatusCode());
    }

    /**
     * @Given OTP was requested for :email from IP :ipAddress :seconds seconds ago
     */
    public function otpWasRequestedSecondsAgo(string $email, string $ipAddress, int $seconds): void
    {
        $this->clock->modify(\sprintf('-%d seconds', $seconds));
        try {
            $this->commandBus->dispatch(new RequestOtpCommand(Email::fromString($email), $ipAddress));
        } finally {
            $this->clock->modify(\sprintf('+%d seconds', $seconds));
        }
    }

    /**
     * @Given a fresh OTP challenge exists for email :email
     */
    public function aFreshOtpChallengeExistsForEmail(string $email): void
    {
        $this->clock->modify(self::OTP_COOLDOWN_ELAPSED_MODIFIER);
        $this->commandBus->dispatch(new RequestOtpCommand(Email::fromString($email)));
    }

    /**
     * @Given an exhausted OTP challenge exists for email :email
     */
    public function anExhaustedOtpChallengeExistsForEmail(string $email): void
    {
        $emailValue = Email::fromString($email);
        $this->commandBus->dispatch(new RequestOtpCommand($emailValue));

        for ($attempt = 0; $attempt < self::OTP_MAX_ATTEMPTS; ++$attempt) {
            $result = $this->commandBus->dispatchWithResult(new VerifyOtpCommand($emailValue, '000000'));
            Assert::assertFalse($result->verified, 'OTP fixture code must be invalid.');
        }
    }

    /**
     * @When I register user :alias with email :email
     */
    public function iRegisterUserWithEmail(string $alias, string $email): void
    {
        $this->commandBus->dispatch(new UpsertUserByEmailCommand($email));

        $user = $this->userQuery->findByEmail(Email::fromString($email));
        Assert::assertNotNull($user, \sprintf('User with email %s was not created', $email));

        $this->registry->putUser($alias, new UserFixture(
            new Id($user->id),
            $email,
        ));
    }

    /**
     * @When the integration events are processed
     */
    public function theIntegrationEventsAreProcessed(): void
    {
        $application = new Application($this->kernel);
        $tester = new CommandTester($application->find('app:process-outbox'));

        $exitCode = $tester->execute(['--once' => true]);

        Assert::assertSame(0, $exitCode, $tester->getDisplay());
    }

    // ========================================
    // Then: Query-based state verification
    // ========================================

    /**
     * @Then the latest OTP challenge for :email should be consumed
     */
    public function theLatestOtpChallengeForShouldBeConsumed(string $email): void
    {
        $challenge = $this->otpChallengeQuery->findLatestByEmail(Email::fromString($email));

        Assert::assertNotNull($challenge, 'OTP challenge not found');
        Assert::assertNotNull($challenge->consumedAt);
    }

    /**
     * @Then the latest OTP challenge for :email should have :attempts attempts
     */
    public function theLatestOtpChallengeForShouldHaveAttempts(string $email, int $attempts): void
    {
        $challenge = $this->otpChallengeQuery->findLatestByEmail(Email::fromString($email));

        Assert::assertNotNull($challenge, 'OTP challenge not found');
        Assert::assertSame($attempts, $challenge->attempts);
    }

    /**
     * @Then the user with email :email should be logged in
     */
    public function theUserWithEmailShouldBeLoggedIn(string $email): void
    {
        $dto = $this->userQuery->findByEmail(Email::fromString($email));

        Assert::assertNotNull($dto, \sprintf('User with email %s not found', $email));
        Assert::assertNotNull($dto->lastLoginAt, 'Expected lastLoginAt to be set');
    }

    /**
     * @Then an integration event for registered user :email should be stored in the outbox
     */
    public function anIntegrationEventForRegisteredUserShouldBeStoredInTheOutbox(string $email): void
    {
        $count = $this->connection->fetchOne(
            "SELECT COUNT(*) FROM shared.async_outbox WHERE event_name = :event_name AND payload ->> 'email' = :email",
            [
                'event_name' => UserRegisteredIntegrationEvent::class,
                'email' => (string) Email::fromString($email),
            ],
        );

        Assert::assertSame(1, $this->intValue($count));
    }

    /**
     * @Then a user registration notification for :email should be stored
     */
    public function aUserRegistrationNotificationForShouldBeStored(string $email): void
    {
        Assert::assertGreaterThanOrEqual(1, $this->notificationCountForEmail($email));
    }

    /**
     * @Then exactly one user registration notification for :email should be stored
     */
    public function exactlyOneUserRegistrationNotificationForShouldBeStored(string $email): void
    {
        Assert::assertSame(1, $this->notificationCountForEmail($email));
    }

    /**
     * @Then session should contain user id for :email
     */
    public function sessionShouldContainUserIdFor(string $email): void
    {
        $dto = $this->userQuery->findByEmail(Email::fromString($email));
        Assert::assertNotNull($dto, \sprintf('User with email %s not found', $email));

        $session = $this->client->getRequest()->getSession();
        Assert::assertSame($dto->id, $session->get('user_id'));
    }

    /**
     * @Then session should contain active client id for :clientAlias
     */
    public function sessionShouldContainActiveClientIdFor(string $clientAlias): void
    {
        $session = $this->client->getRequest()->getSession();
        Assert::assertSame($this->registry->getClient($clientAlias)->id(), $session->get('active_client_id'));
    }

    /**
     * @Then OTP verify response should be ok true
     */
    public function otpVerifyResponseShouldBeOkTrue(): void
    {
        $response = $this->client->getResponse();
        Assert::assertSame(200, $response->getStatusCode());

        $content = $response->getContent();
        Assert::assertIsString($content);

        /** @var array{ok: bool} $data */
        $data = json_decode($content, true, 512, \JSON_THROW_ON_ERROR);
        Assert::assertArrayHasKey('ok', $data);
        Assert::assertTrue($data['ok']);
    }

    /**
     * @Then OTP verify response should be ok false
     */
    public function otpVerifyResponseShouldBeOkFalse(): void
    {
        $response = $this->client->getResponse();
        Assert::assertSame(200, $response->getStatusCode());

        $content = $response->getContent();
        Assert::assertIsString($content);

        /** @var array{ok: bool} $data */
        $data = json_decode($content, true, 512, \JSON_THROW_ON_ERROR);
        Assert::assertArrayHasKey('ok', $data);
        Assert::assertFalse($data['ok']);
    }

    /**
     * @Then session should not contain user id
     */
    public function sessionShouldNotContainUserId(): void
    {
        $session = $this->client->getRequest()->getSession();
        Assert::assertNull($session->get('user_id'));
    }

    private function notificationCountForEmail(string $email): int
    {
        if (!is_file($this->userNotificationLogPath)) {
            return 0;
        }

        $lines = file($this->userNotificationLogPath, \FILE_IGNORE_NEW_LINES | \FILE_SKIP_EMPTY_LINES);
        if (false === $lines) {
            throw new \RuntimeException(\sprintf('Notification file "%s" could not be read.', $this->userNotificationLogPath));
        }

        return \count(array_filter(
            $lines,
            static fn (string $line): bool => str_contains($line, ' email=' . (string) Email::fromString($email) . ' '),
        ));
    }

    /** @return list<array{email: string, code: string}> */
    private function otpMessages(): array
    {
        if (!is_file($this->otpMailboxPath)) {
            return [];
        }

        $lines = file($this->otpMailboxPath, \FILE_IGNORE_NEW_LINES | \FILE_SKIP_EMPTY_LINES);
        Assert::assertNotFalse($lines);
        $messages = [];
        foreach ($lines as $line) {
            $message = json_decode($line, true, 512, \JSON_THROW_ON_ERROR);
            Assert::assertIsArray($message);
            Assert::assertArrayHasKey('email', $message);
            Assert::assertArrayHasKey('code', $message);
            Assert::assertIsString($message['email']);
            Assert::assertIsString($message['code']);
            $messages[] = ['email' => $message['email'], 'code' => $message['code']];
        }

        return $messages;
    }

    private function intValue(mixed $value): int
    {
        if (!\is_int($value) && !\is_string($value)) {
            throw new \RuntimeException('Expected an integer-compatible value.');
        }

        return (int) $value;
    }
}
