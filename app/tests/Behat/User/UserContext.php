<?php

declare(strict_types=1);

namespace App\Tests\Behat\User;

use App\SharedKernel\Application\CommandBus\CommandBusInterface;
use App\SharedKernel\Domain\Clock\MutableClock;
use App\SharedKernel\Domain\ValueObject\Email;
use App\Tests\Behat\Support\Fixture\FixtureRegistry;
use App\User\Application\OtpChallenge\Command\RequestOtp\RequestOtpCommand;
use App\User\Application\OtpChallenge\Command\VerifyOtp\VerifyOtpCommand;
use App\User\Application\OtpChallenge\Query\OtpChallengeQueryInterface;
use App\User\Application\User\Query\UserQueryInterface;
use Behat\Behat\Context\Context;
use Behat\Gherkin\Node\TableNode;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Assert;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\BrowserKit\Cookie;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpKernel\KernelInterface;

final class UserContext implements Context
{
    private const string OTP_COOLDOWN_ELAPSED_MODIFIER = '+61 seconds';

    private ?string $deliveredOtpCode = null;

    private ?string $sessionIdBeforeClientSelection = null;

    public function __construct(
        private readonly KernelBrowser $client,
        private readonly Connection $connection,
        private readonly UserQueryInterface $userQuery,
        private readonly OtpChallengeQueryInterface $otpChallengeQuery,
        private readonly FixtureRegistry $registry,
        private readonly CommandBusInterface $commandBus,
        private readonly MutableClock $clock,
        private readonly KernelInterface $kernel,
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
     * @When I request my active clients
     */
    public function iRequestMyActiveClients(): void
    {
        $this->client->request('GET', '/api/me/clients');
    }

    /**
     * @When I select active client :clientAlias
     */
    public function iSelectActiveClient(string $clientAlias): void
    {
        $this->iSelectActiveClientWithId($this->registry->getClient($clientAlias)->id());
    }

    /**
     * @When I select active client :clientAlias using an uppercase id
     */
    public function iSelectActiveClientUsingAnUppercaseId(string $clientAlias): void
    {
        $this->iSelectActiveClientWithId(strtoupper($this->registry->getClient($clientAlias)->id()));
    }

    /**
     * @When I select active client with id :clientId
     */
    public function iSelectActiveClientWithId(string $clientId): void
    {
        $this->sessionIdBeforeClientSelection = $this->currentSessionId();

        $this->client->request(
            'POST',
            '/api/session/active-client',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['clientId' => $clientId], \JSON_THROW_ON_ERROR),
        );
    }

    /**
     * @When I list members of client :clientAlias
     */
    public function iListMembersOfClient(string $clientAlias): void
    {
        $clientId = $this->registry->getClient($clientAlias)->id();

        $this->client->request('GET', "/api/clients/{$clientId}/members");
    }

    /**
     * @When I create a client named :name
     */
    public function iCreateAClientNamed(string $name): void
    {
        $this->client->request(
            'POST',
            '/api/clients',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['name' => $name, 'adminEmail' => 'first-admin@example.com'], \JSON_THROW_ON_ERROR),
        );
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
     * @Given an OTP challenge with :attempts failed attempts exists for email :email
     */
    public function anOtpChallengeWithFailedAttemptsExistsForEmail(int $attempts, string $email): void
    {
        $emailValue = Email::fromString($email);
        $this->commandBus->dispatch(new RequestOtpCommand($emailValue));

        for ($attempt = 0; $attempt < $attempts; ++$attempt) {
            $result = $this->commandBus->dispatchWithResult(new VerifyOtpCommand($emailValue, '000000'));
            Assert::assertFalse($result->verified, 'OTP fixture code must be invalid.');
        }
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
     * @Then the latest OTP challenge for :email should not be consumed
     */
    public function theLatestOtpChallengeForShouldNotBeConsumed(string $email): void
    {
        $challenge = $this->otpChallengeQuery->findLatestByEmail(Email::fromString($email));

        Assert::assertNotNull($challenge, 'OTP challenge not found');
        Assert::assertNull($challenge->consumedAt);
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
     * @Then session should not contain active client id
     */
    public function sessionShouldNotContainActiveClientId(): void
    {
        $session = $this->client->getRequest()->getSession();
        Assert::assertNull($session->get('active_client_id'));
    }

    /**
     * @Then the session id should have changed on client selection
     */
    public function theSessionIdShouldHaveChangedOnClientSelection(): void
    {
        Assert::assertNotNull($this->sessionIdBeforeClientSelection);
        Assert::assertNotSame($this->sessionIdBeforeClientSelection, $this->currentSessionId());
    }

    /**
     * @Then the session id from before the client selection should no longer be logged in
     */
    public function theSessionIdFromBeforeTheClientSelectionShouldNoLongerBeLoggedIn(): void
    {
        Assert::assertNotNull($this->sessionIdBeforeClientSelection);
        $sessionName = $this->client->getRequest()->getSession()->getName();
        $currentSessionId = $this->currentSessionId();

        $this->client->getCookieJar()->set(new Cookie($sessionName, $this->sessionIdBeforeClientSelection));
        $this->client->request('GET', '/api/me/clients');
        $this->theResponseStatusShouldBe(401);

        $this->client->getCookieJar()->set(new Cookie($sessionName, $currentSessionId));
    }

    /**
     * @Then the response status should be :statusCode
     */
    public function theResponseStatusShouldBe(int $statusCode): void
    {
        $response = $this->client->getResponse();
        Assert::assertSame($statusCode, $response->getStatusCode(), \sprintf(
            'Expected status %d, got %d. Response: %s',
            $statusCode,
            $response->getStatusCode(),
            (string) $response->getContent(),
        ));
    }

    /**
     * @Then the response error should be :error
     */
    public function theResponseErrorShouldBe(string $error): void
    {
        Assert::assertSame(
            ['error' => $error],
            json_decode((string) $this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR),
        );
    }

    /**
     * @Then my active clients response should contain exactly:
     */
    public function myActiveClientsResponseShouldContainExactly(TableNode $table): void
    {
        $this->theResponseStatusShouldBe(200);

        $expected = array_map(
            function (array $row): array {
                $client = $this->registry->getClient($row['client']);

                return [
                    'clientId' => $client->id(),
                    'clientName' => $client->name,
                    'roles' => array_map('trim', explode(',', $row['roles'])),
                ];
            },
            $table->getColumnsHash(),
        );

        /** @var list<array{clientId: string, clientName: string, roles: array<string>}> $actual */
        $actual = json_decode((string) $this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);

        $byClientId = static fn (array $left, array $right): int => $left['clientId'] <=> $right['clientId'];
        usort($expected, $byClientId);
        usort($actual, $byClientId);

        Assert::assertSame($expected, $actual);
    }

    /**
     * @Then my active clients response should be empty
     */
    public function myActiveClientsResponseShouldBeEmpty(): void
    {
        $this->theResponseStatusShouldBe(200);
        Assert::assertSame('[]', $this->client->getResponse()->getContent());
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
        Assert::assertSame(['ok' => true], $data);
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
        Assert::assertSame(['ok' => false], $data);
    }

    /**
     * @Then session should not contain user id
     */
    public function sessionShouldNotContainUserId(): void
    {
        $session = $this->client->getRequest()->getSession();
        Assert::assertNull($session->get('user_id'));
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

    private function currentSessionId(): string
    {
        return $this->client->getRequest()->getSession()->getId();
    }

    private function intValue(mixed $value): int
    {
        if (!\is_int($value) && !\is_string($value)) {
            throw new \RuntimeException('Expected an integer-compatible value.');
        }

        return (int) $value;
    }
}
