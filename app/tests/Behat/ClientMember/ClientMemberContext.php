<?php

declare(strict_types=1);

namespace App\Tests\Behat\ClientMember;

use App\Client\Application\ClientMember\Query\ClientMemberQueryInterface;
use App\SharedKernel\Domain\ValueObject\Email;
use App\SharedKernel\Domain\ValueObject\Id;
use App\Tests\Behat\Support\Fixture\FixtureRegistry;
use App\Tests\Behat\Support\Http\AuthenticatedSessionApplier;
use App\User\Application\User\Query\UserQueryInterface;
use Behat\Behat\Context\Context;
use PHPUnit\Framework\Assert;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

final class ClientMemberContext implements Context
{
    private ?int $lastResponseCode = null;

    public function __construct(
        private readonly KernelBrowser $client,
        private readonly FixtureRegistry $registry,
        private readonly ClientMemberQueryInterface $clientMemberQuery,
        private readonly UserQueryInterface $userQuery,
        private readonly AuthenticatedSessionApplier $sessionApplier,
    ) {
    }

    // ========================================
    // When: HTTP endpoints only
    // ========================================

    /**
     * @When I provision a member with email :email
     */
    public function iProvisionAMemberWithEmail(string $email): void
    {
        $this->sessionApplier->apply($this->client);

        $this->client->request(
            'POST',
            '/api/client-members',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['email' => $email], \JSON_THROW_ON_ERROR),
        );

        $this->lastResponseCode = $this->client->getResponse()->getStatusCode();
    }

    /**
     * @When I replace roles for :userAlias in :clientAlias with :roles
     */
    public function iReplaceRolesForInWith(string $userAlias, string $clientAlias, string $roles): void
    {
        $this->sessionApplier->apply($this->client);

        $clientId = $this->registry->getClient($clientAlias)->id();
        $userId = $this->registry->getUser($userAlias)->id();
        $rolesArray = array_map('trim', explode(',', $roles));

        $this->client->request(
            'PUT',
            "/api/clients/{$clientId}/members/{$userId}/roles",
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['roles' => $rolesArray], \JSON_THROW_ON_ERROR),
        );

        $this->lastResponseCode = $this->client->getResponse()->getStatusCode();
    }

    /**
     * @When I suspend :userAlias in :clientAlias
     */
    public function iSuspendIn(string $userAlias, string $clientAlias): void
    {
        $this->sessionApplier->apply($this->client);

        $clientId = $this->registry->getClient($clientAlias)->id();
        $userId = $this->registry->getUser($userAlias)->id();

        $this->client->request('POST', "/api/clients/{$clientId}/members/{$userId}/suspend");
        $this->lastResponseCode = $this->client->getResponse()->getStatusCode();
    }

    /**
     * @When I unsuspend :userAlias in :clientAlias
     */
    public function iUnsuspendIn(string $userAlias, string $clientAlias): void
    {
        $this->sessionApplier->apply($this->client);

        $clientId = $this->registry->getClient($clientAlias)->id();
        $userId = $this->registry->getUser($userAlias)->id();

        $this->client->request('POST', "/api/clients/{$clientId}/members/{$userId}/unsuspend");
        $this->lastResponseCode = $this->client->getResponse()->getStatusCode();
    }

    /**
     * @When I list members in :clientAlias
     */
    public function iListMembersIn(string $clientAlias): void
    {
        $this->sessionApplier->apply($this->client);

        $clientId = $this->registry->getClient($clientAlias)->id();

        $this->client->request('GET', "/api/clients/{$clientId}/members");
        $this->lastResponseCode = $this->client->getResponse()->getStatusCode();
    }

    // ========================================
    // Then: HTTP status code assertions
    // ========================================

    /**
     * @Then the membership should be created successfully
     */
    public function theMembershipShouldBeCreatedSuccessfully(): void
    {
        Assert::assertSame(201, $this->lastResponseCode, \sprintf(
            'Expected status 201, got %d. Response: %s',
            $this->lastResponseCode,
            (string) $this->client->getResponse()->getContent(),
        ));
    }

    /**
     * @Then I should get a conflict error
     */
    public function iShouldGetAConflictError(): void
    {
        Assert::assertSame(409, $this->lastResponseCode, \sprintf(
            'Expected status 409, got %d',
            $this->lastResponseCode,
        ));
    }

    /**
     * @Then the operation should succeed
     */
    public function theOperationShouldSucceed(): void
    {
        Assert::assertSame(204, $this->lastResponseCode, \sprintf(
            'Expected status 204, got %d',
            $this->lastResponseCode,
        ));
    }

    /**
     * @Then response status should be :statusCode
     */
    public function responseStatusShouldBe(int $statusCode): void
    {
        Assert::assertSame($statusCode, $this->lastResponseCode, \sprintf(
            'Expected status %d, got %d. Response: %s',
            $statusCode,
            $this->lastResponseCode,
            (string) $this->client->getResponse()->getContent(),
        ));
    }

    // ========================================
    // Then: Query-based state verification
    // ========================================

    /**
     * @Then client :clientAlias should have :count member(s)
     */
    public function clientShouldHaveMembers(string $clientAlias, int $count): void
    {
        $clientId = $this->registry->getClient($clientAlias)->id;
        $members = $this->clientMemberQuery->listByClient($clientId);
        Assert::assertCount($count, $members);
    }

    /**
     * @Then the member :userAlias in client :clientAlias should have roles :roles
     */
    public function theMemberInClientShouldHaveRoles(string $userAlias, string $clientAlias, string $roles): void
    {
        $clientId = $this->registry->getClient($clientAlias)->id;
        $userId = $this->registry->getUser($userAlias)->id;
        $expectedRoles = array_map('trim', explode(',', $roles));
        sort($expectedRoles);

        $dto = $this->clientMemberQuery->findByClientAndUser($clientId, $userId);

        Assert::assertNotNull($dto, \sprintf('Member %s not found in client %s', $userAlias, $clientAlias));
        Assert::assertSame($expectedRoles, $dto->roles);
    }

    /**
     * @Then the member :userAlias in client :clientAlias should have status :status
     */
    public function theMemberInClientShouldHaveStatus(string $userAlias, string $clientAlias, string $status): void
    {
        $clientId = $this->registry->getClient($clientAlias)->id;
        $userId = $this->registry->getUser($userAlias)->id;

        $dto = $this->clientMemberQuery->findByClientAndUser($clientId, $userId);

        Assert::assertNotNull($dto, \sprintf('Member %s not found in client %s', $userAlias, $clientAlias));
        Assert::assertSame($status, $dto->status);
    }

    /**
     * @Then the provisioned member :email in client :clientAlias should have roles :roles
     */
    public function theProvisionedMemberInClientShouldHaveRoles(string $email, string $clientAlias, string $roles): void
    {
        $user = $this->userQuery->findByEmail(Email::fromString($email));
        Assert::assertNotNull($user, \sprintf('User with email %s not found', $email));

        $clientId = $this->registry->getClient($clientAlias)->id;
        $dto = $this->clientMemberQuery->findByClientAndUser($clientId, new Id($user->id));

        Assert::assertNotNull($dto, \sprintf('Membership for %s not found in client %s', $email, $clientAlias));

        $expectedRoles = array_map('trim', explode(',', $roles));
        sort($expectedRoles);
        Assert::assertSame($expectedRoles, $dto->roles);
    }

    /**
     * @Then the provisioned member :email in client :clientAlias should have status :status
     */
    public function theProvisionedMemberInClientShouldHaveStatus(string $email, string $clientAlias, string $status): void
    {
        $user = $this->userQuery->findByEmail(Email::fromString($email));
        Assert::assertNotNull($user, \sprintf('User with email %s not found', $email));

        $clientId = $this->registry->getClient($clientAlias)->id;
        $dto = $this->clientMemberQuery->findByClientAndUser($clientId, new Id($user->id));

        Assert::assertNotNull($dto, \sprintf('Membership for %s not found in client %s', $email, $clientAlias));
        Assert::assertSame($status, $dto->status);
    }

    /**
     * @Then there should be exactly :count membership(s) for client :clientAlias
     */
    public function thereShouldBeExactlyMembershipsForClient(int $count, string $clientAlias): void
    {
        $clientId = $this->registry->getClient($clientAlias)->id;
        $members = $this->clientMemberQuery->listByClient($clientId);
        Assert::assertCount($count, $members, \sprintf(
            'Expected %d memberships, found %d',
            $count,
            \count($members),
        ));
    }
}
