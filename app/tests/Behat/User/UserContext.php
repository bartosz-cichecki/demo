<?php

declare(strict_types=1);

namespace App\Tests\Behat\User;

use App\SharedKernel\Domain\ValueObject\Email;
use App\Tests\Behat\Support\Fixture\FixtureRegistry;
use App\User\Application\User\Query\UserQueryInterface;
use Behat\Behat\Context\Context;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Assert;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

final class UserContext implements Context
{
    public function __construct(
        private readonly KernelBrowser $client,
        private readonly Connection $connection,
        private readonly UserQueryInterface $userQuery,
        private readonly FixtureRegistry $registry,
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
        $this->client->request(
            'POST',
            '/api/auth/otp/request',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['email' => $email], \JSON_THROW_ON_ERROR),
        );

        $response = $this->client->getResponse();
        Assert::assertSame(200, $response->getStatusCode());
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

    // ========================================
    // Then: Query-based state verification
    // ========================================

    /**
     * @Then the latest OTP challenge for :email should be consumed
     */
    public function theLatestOtpChallengeForShouldBeConsumed(string $email): void
    {
        $row = $this->connection->fetchAssociative(
            'SELECT consumed_at FROM "user".otp_challenges WHERE email = :email ORDER BY last_sent_at DESC LIMIT 1',
            ['email' => (string) Email::fromString($email)],
        );

        Assert::assertIsArray($row, 'OTP challenge not found');
        Assert::assertNotNull($row['consumed_at']);
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
}
