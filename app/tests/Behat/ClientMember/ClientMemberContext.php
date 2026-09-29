<?php

declare(strict_types=1);

namespace App\Tests\Behat\ClientMember;

use App\Client\Application\ClientMember\Query\ClientMemberQueryInterface;
use App\Tests\Behat\Support\Fixture\FixtureRegistry;
use App\Tests\Behat\Support\Http\AuthenticatedSessionApplier;
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
        private readonly AuthenticatedSessionApplier $sessionApplier,
    ) {
    }

    // ========================================
    // When: HTTP endpoints only
    // ========================================

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

    /**
     * @Then response error should be :error
     */
    public function responseErrorShouldBe(string $error): void
    {
        Assert::assertSame(
            ['error' => $error],
            json_decode((string) $this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR),
        );
    }

    // ========================================
    // Then: Query-based state verification
    // ========================================

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
