<?php

declare(strict_types=1);

namespace App\Tests\Behat\Platform;

use App\Client\Application\Client\Query\ClientQueryInterface;
use App\Client\Application\ClientInvitation\Query\ClientInvitationQueryInterface;
use App\SharedKernel\Domain\ValueObject\Email;
use App\SharedKernel\Domain\ValueObject\Id;
use App\Tests\Behat\Support\Fixture\ClientFixture;
use App\Tests\Behat\Support\Fixture\FixtureRegistry;
use App\Tests\Behat\Support\Http\AuthenticatedSessionApplier;
use Behat\Behat\Context\Context;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Assert;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

final class PlatformClientContext implements Context
{
    private ?string $createdClientId = null;

    public function __construct(
        private readonly KernelBrowser $client,
        private readonly ClientQueryInterface $clientQuery,
        private readonly AuthenticatedSessionApplier $sessionApplier,
        private readonly FixtureRegistry $registry,
        private readonly ClientInvitationQueryInterface $invitationQuery,
        private readonly Connection $connection,
    ) {
    }

    /**
     * @Given I am anonymous
     */
    public function iAmAnonymous(): void
    {
        $this->registry->setAuthenticatedSession('', null);
        $this->client->restart();
    }

    /**
     * @Then no client named :name should exist
     */
    public function noClientNamedShouldExist(string $name): void
    {
        Assert::assertSame([], $this->connection->fetchFirstColumn('SELECT id FROM client.clients WHERE name = :name', ['name' => $name]));
    }

    /**
     * @When I onboard client :name with admin email :email
     */
    public function iOnboardClientWithAdminEmail(string $name, string $email): void
    {
        $this->createClient($name, ['name' => $name, 'adminEmail' => $email]);
        if (201 === $this->client->getResponse()->getStatusCode()) {
            $invitation = $this->invitationQuery->findPendingByClientAndEmail(
                $this->registry->getClient($name)->id,
                Email::fromString($email),
            );
            Assert::assertNotNull($invitation);
            $this->registry->putInvitation($email, $name, new Id($invitation->id));
        }
    }

    /**
     * @When I onboard client :name without admin email
     */
    public function iOnboardClientWithoutAdminEmail(string $name): void
    {
        $this->createClient($name, ['name' => $name]);
    }

    /** @param array<string, string> $payload */
    private function createClient(string $name, array $payload): void
    {
        $this->sessionApplier->apply($this->client);
        $this->client->request('POST', '/api/clients', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode($payload, \JSON_THROW_ON_ERROR));
        if (201 === $this->client->getResponse()->getStatusCode()) {
            /** @var array{id: string} $data */
            $data = json_decode((string) $this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
            $this->createdClientId = $data['id'];
            $this->registry->putClient($name, new ClientFixture(new Id($data['id']), $name));
        }
    }

    /**
     * @When I invite admin :email to client :alias via platform API
     */
    public function iInviteAdminToClientViaPlatformApi(string $email, string $alias): void
    {
        $this->adminInvitationRequest($email, $alias, '');
        if (201 === $this->client->getResponse()->getStatusCode()) {
            /** @var array{id: string} $data */
            $data = json_decode((string) $this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
            $this->registry->putInvitation($email, $alias, new Id($data['id']));
        }
    }

    /**
     * @When I revoke admin :email from client :alias via platform API
     */
    public function iRevokeAdminFromClientViaPlatformApi(string $email, string $alias): void
    {
        $this->adminInvitationRequest($email, $alias, '/revoke');
    }

    /**
     * @When I invite an admin to a nonexistent client via platform API
     */
    public function iInviteAnAdminToANonexistentClientViaPlatformApi(): void
    {
        $this->registry->putClient('absent', new ClientFixture(Id::new(), 'absent'));
        $this->adminInvitationRequest('admin@example.com', 'absent', '');
    }

    private function adminInvitationRequest(string $email, string $alias, string $suffix): void
    {
        $this->sessionApplier->apply($this->client);
        $id = $this->registry->getClient($alias)->id();
        $this->client->request('POST', "/api/clients/{$id}/admin-invitations{$suffix}", [], [], ['CONTENT_TYPE' => 'application/json'], json_encode(['email' => $email], \JSON_THROW_ON_ERROR));
    }

    /**
     * @Then a client named :name should exist
     */
    public function aClientNamedShouldExist(string $name): void
    {
        Assert::assertNotNull($this->createdClientId, 'No client was created');

        $dto = $this->clientQuery->findById(new Id($this->createdClientId));

        Assert::assertNotNull($dto);
        Assert::assertSame($name, $dto->name);
    }
}
