<?php

declare(strict_types=1);

namespace App\Tests\Behat\Support\Fixture;

use App\Client\Application\Client\Command\CreateClient\CreateClientCommand;
use App\Client\Application\ClientMember\Command\CreateClientMember\CreateClientMemberCommand;
use App\Client\Application\ClientMember\Command\SuspendClientMember\SuspendClientMemberCommand;
use App\SharedKernel\Application\CommandBus\CommandBusInterface;
use App\SharedKernel\Domain\ValueObject\Email;
use App\SharedKernel\Domain\ValueObject\Id;
use App\User\Application\User\Command\UpsertUserByEmail\UpsertUserByEmailCommand;
use App\User\Application\User\Query\UserQueryInterface;
use Behat\Behat\Context\Context;
use Behat\Behat\Hook\Scope\BeforeScenarioScope;
use Doctrine\DBAL\Connection;

final class FixtureContext implements Context
{
    public function __construct(
        private readonly FixtureRegistry $registry,
        private readonly CommandBusInterface $commandBus,
        private readonly Connection $connection,
        private readonly UserQueryInterface $userQuery,
    ) {
    }

    /**
     * @BeforeScenario
     */
    public function clearFixtures(BeforeScenarioScope $scope): void
    {
        $this->registry->clear();
        $this->connection->executeStatement('DELETE FROM client.client_memberships');
        $this->connection->executeStatement('DELETE FROM client.clients');
        $this->connection->executeStatement('DELETE FROM "user".otp_challenges');
        $this->connection->executeStatement('DELETE FROM "user".users');
        $this->connection->executeStatement('DELETE FROM shared.event_log');
    }

    /**
     * @Given there is a client :alias
     */
    public function thereIsAClient(string $alias): void
    {
        $id = DeterministicUuid::fromAlias('client:' . $alias);
        $fixture = new ClientFixture($id, $alias);

        $this->commandBus->dispatch(new CreateClientCommand(
            $id,
            $fixture->name,
            $fixture->description,
        ));

        $this->registry->putClient($alias, $fixture);
    }

    /**
     * @Given there is a user :alias with email :email
     */
    public function thereIsAUserWithEmail(string $alias, string $email): void
    {
        $this->commandBus->dispatch(new UpsertUserByEmailCommand($email));

        $dto = $this->userQuery->findByEmail(Email::fromString($email));
        if (null === $dto) {
            throw new \RuntimeException(\sprintf('User with email %s was not created', $email));
        }

        $fixture = new UserFixture(
            new Id($dto->id),
            $email,
        );

        $this->registry->putUser($alias, $fixture);
    }

    /**
     * @Given there is a membership of :userAlias in :clientAlias with roles :roles
     */
    public function thereIsAMembershipOfInWithRoles(string $userAlias, string $clientAlias, string $roles): void
    {
        $clientId = $this->registry->getClient($clientAlias)->id;
        $userId = $this->registry->getUser($userAlias)->id;
        $rolesArray = array_map('trim', explode(',', $roles));

        $this->commandBus->dispatch(new CreateClientMemberCommand(
            $clientId,
            $userId,
            $rolesArray,
        ));
    }

    /**
     * @Given membership of :userAlias in :clientAlias is suspended
     */
    public function membershipOfInIsSuspended(string $userAlias, string $clientAlias): void
    {
        $clientId = $this->registry->getClient($clientAlias)->id;
        $userId = $this->registry->getUser($userAlias)->id;

        $this->commandBus->dispatch(new SuspendClientMemberCommand(
            $clientId,
            $userId,
        ));
    }

    /**
     * @Given I am logged in as :userAlias in client :clientAlias
     */
    public function iAmLoggedInAsInClient(string $userAlias, string $clientAlias): void
    {
        $userId = $this->registry->getUser($userAlias)->id();
        $activeClientId = $this->registry->getClient($clientAlias)->id();
        $this->registry->setAuthenticatedSession($userId, $activeClientId);
    }

    /**
     * @Given I am logged in as :userAlias without active client
     */
    public function iAmLoggedInAsWithoutActiveClient(string $userAlias): void
    {
        $userId = $this->registry->getUser($userAlias)->id();
        $this->registry->setAuthenticatedSession($userId, null);
    }

    /**
     * @Given I am logged in as platform admin :userAlias
     */
    public function iAmLoggedInAsPlatformAdmin(string $userAlias): void
    {
        $userId = $this->registry->getUser($userAlias)->id();
        $this->registry->setAuthenticatedSession($userId, null, true);
    }
}
