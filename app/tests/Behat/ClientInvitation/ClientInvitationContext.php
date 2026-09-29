<?php

declare(strict_types=1);

namespace App\Tests\Behat\ClientInvitation;

use App\Client\Application\ClientInvitation\Query\ClientInvitationQueryInterface;
use App\Client\Application\ClientMember\Query\ClientMemberQueryInterface;
use App\Client\Application\ClientMember\Query\Dto\ClientMemberDto;
use App\Client\Application\IntegrationEvent\ClientInvitationCreatedIntegrationEvent;
use App\SharedKernel\Domain\ValueObject\Email;
use App\SharedKernel\Domain\ValueObject\Id;
use App\Tests\Behat\Support\Fixture\FixtureRegistry;
use App\Tests\Behat\Support\Http\AuthenticatedSessionApplier;
use App\User\Application\IntegrationEventSubscriber\SendClientInvitationNotificationSubscriber;
use App\User\Application\User\Query\UserQueryInterface;
use Behat\Behat\Context\Context;
use Behat\Gherkin\Node\TableNode;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Assert;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

final class ClientInvitationContext implements Context
{
    private ?string $sessionIdBeforeAccept = null;

    public function __construct(
        private readonly KernelBrowser $client,
        private readonly Connection $connection,
        private readonly FixtureRegistry $registry,
        private readonly AuthenticatedSessionApplier $sessionApplier,
        private readonly ClientInvitationQueryInterface $clientInvitationQuery,
        private readonly ClientMemberQueryInterface $clientMemberQuery,
        private readonly UserQueryInterface $userQuery,
        private readonly string $userNotificationLogPath,
    ) {
    }

    // ========================================
    // When: HTTP endpoints only
    // ========================================

    /**
     * @When I invite :email to client :clientAlias with role :role
     */
    public function iInviteToClientWithRole(string $email, string $clientAlias, string $role): void
    {
        $this->sessionApplier->apply($this->client);

        $clientId = $this->registry->getClient($clientAlias)->id();
        $this->client->request(
            'POST',
            "/api/clients/{$clientId}/invitations",
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['email' => $email, 'role' => $role], \JSON_THROW_ON_ERROR),
        );

        if (201 === $this->client->getResponse()->getStatusCode()) {
            /** @var array{id: string} $data */
            $data = json_decode((string) $this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
            $this->registry->putInvitation($email, $clientAlias, new Id($data['id']));
        }
    }

    /**
     * @When I revoke the invitation of :email to :clientAlias
     */
    public function iRevokeTheInvitationOfTo(string $email, string $clientAlias): void
    {
        $this->iRevokeTheInvitationOfToThroughClient($email, $clientAlias, $clientAlias);
    }

    /**
     * @When I revoke the invitation of :email to :invitationClientAlias through client :clientAlias
     */
    public function iRevokeTheInvitationOfToThroughClient(string $email, string $invitationClientAlias, string $clientAlias): void
    {
        $this->sessionApplier->apply($this->client);

        $clientId = $this->registry->getClient($clientAlias)->id();
        $invitationId = $this->registry->getInvitation($email, $invitationClientAlias);
        $this->client->request('POST', "/api/clients/{$clientId}/invitations/{$invitationId}/revoke");
    }

    /**
     * Invitee actions use the session established by the preceding OTP login.
     *
     * @When I list my invitations
     */
    public function iListMyInvitations(): void
    {
        $this->client->request('GET', '/api/me/invitations');
    }

    /**
     * @When I accept the invitation of :email to :clientAlias
     */
    public function iAcceptTheInvitationOfTo(string $email, string $clientAlias): void
    {
        $this->sessionIdBeforeAccept = $this->client->getRequest()->getSession()->getId();
        $invitationId = $this->registry->getInvitation($email, $clientAlias);
        $this->client->request('POST', "/api/invitations/{$invitationId}/accept");
    }

    /**
     * @When I reject the invitation of :email to :clientAlias
     */
    public function iRejectTheInvitationOfTo(string $email, string $clientAlias): void
    {
        $invitationId = $this->registry->getInvitation($email, $clientAlias);
        $this->client->request('POST', "/api/invitations/{$invitationId}/reject");
    }

    // ========================================
    // Then: HTTP response assertions
    // ========================================

    /**
     * @Then my invitations response should contain exactly:
     */
    public function myInvitationsResponseShouldContainExactly(TableNode $table): void
    {
        Assert::assertSame(200, $this->client->getResponse()->getStatusCode());

        $expected = array_map(
            function (array $row): array {
                $client = $this->registry->getClient($row['client']);

                return [
                    'id' => (string) $this->registry->getInvitation($row['email'], $row['client']),
                    'clientId' => $client->id(),
                    'clientName' => $client->name,
                    'role' => $row['role'],
                ];
            },
            $table->getColumnsHash(),
        );

        /** @var list<array{id: string, clientId: string, clientName: string, role: string, createdAt: string}> $response */
        $response = json_decode((string) $this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        $actual = array_map(static function (array $invitation): array {
            Assert::assertNotSame('', $invitation['createdAt']);
            unset($invitation['createdAt']);

            return $invitation;
        }, $response);

        $byId = static fn (array $left, array $right): int => $left['id'] <=> $right['id'];
        usort($expected, $byId);
        usort($actual, $byId);

        Assert::assertSame($expected, $actual);
    }

    /**
     * @Then my invitations response should be empty
     */
    public function myInvitationsResponseShouldBeEmpty(): void
    {
        Assert::assertSame(200, $this->client->getResponse()->getStatusCode());
        Assert::assertSame('[]', $this->client->getResponse()->getContent());
    }

    /**
     * @Then the session id should have changed on accepting the invitation
     */
    public function theSessionIdShouldHaveChangedOnAcceptingTheInvitation(): void
    {
        Assert::assertNotNull($this->sessionIdBeforeAccept);
        Assert::assertNotSame($this->sessionIdBeforeAccept, $this->client->getRequest()->getSession()->getId());
    }

    // ========================================
    // Then: Query-based state verification
    // ========================================

    /**
     * @Then the invitation of :email to :clientAlias should have status :status
     */
    public function theInvitationOfToShouldHaveStatus(string $email, string $clientAlias, string $status): void
    {
        $invitation = $this->clientInvitationQuery->findById($this->registry->getInvitation($email, $clientAlias));

        Assert::assertNotNull($invitation);
        Assert::assertSame($status, $invitation->status);
    }

    /**
     * @Then a pending invitation of :email to :clientAlias with role :role should exist
     */
    public function aPendingInvitationOfToWithRoleShouldExist(string $email, string $clientAlias, string $role): void
    {
        $invitation = $this->clientInvitationQuery->findPendingByClientAndEmail(
            $this->registry->getClient($clientAlias)->id,
            Email::fromString($email),
        );

        Assert::assertNotNull($invitation);
        Assert::assertSame((string) $this->registry->getInvitation($email, $clientAlias), $invitation->id);
        Assert::assertSame($role, $invitation->role);
    }

    /**
     * @Then there should be :count invitation(s) of :email to :clientAlias
     */
    public function thereShouldBeInvitationsOfTo(int $count, string $email, string $clientAlias): void
    {
        $actual = $this->connection->fetchOne(
            'SELECT COUNT(*) FROM client.client_invitations WHERE client_id = :clientId AND email = :email',
            [
                'clientId' => $this->registry->getClient($clientAlias)->id(),
                'email' => (string) Email::fromString($email),
            ],
        );

        Assert::assertSame($count, $this->intValue($actual));
    }

    /**
     * @Then :email should be a member of :clientAlias with roles :roles and status :status
     */
    public function shouldBeAMemberOfWithRolesAndStatus(string $email, string $clientAlias, string $roles, string $status): void
    {
        $membership = $this->membership($email, $clientAlias);
        $expectedRoles = array_map('trim', explode(',', $roles));
        sort($expectedRoles);

        Assert::assertNotNull($membership, \sprintf('Membership of %s in %s not found', $email, $clientAlias));
        Assert::assertSame($expectedRoles, $membership->roles);
        Assert::assertSame($status, $membership->status);
    }

    /**
     * @Then :email should not be a member of :clientAlias
     */
    public function shouldNotBeAMemberOf(string $email, string $clientAlias): void
    {
        Assert::assertNull($this->membership($email, $clientAlias));
    }

    /**
     * @Then client :clientAlias should have :count member(s)
     */
    public function clientShouldHaveMembers(string $clientAlias, int $count): void
    {
        Assert::assertCount($count, $this->clientMemberQuery->listByClient($this->registry->getClient($clientAlias)->id));
    }

    /**
     * @Then no user with email :email should exist
     */
    public function noUserWithEmailShouldExist(string $email): void
    {
        Assert::assertNull($this->userQuery->findByEmail(Email::fromString($email)));
    }

    /**
     * @Then :count invitation notification(s) to :clientAlias for :email should be stored
     */
    public function invitationNotificationsToForShouldBeStored(int $count, string $clientAlias, string $email): void
    {
        $client = $this->registry->getClient($clientAlias);
        $notifications = array_values(array_filter(
            $this->notifications(),
            static fn (array $notification): bool => $notification['email'] === (string) Email::fromString($email)
                && $notification['clientName'] === $client->name,
        ));

        Assert::assertCount($count, $notifications);
        foreach ($notifications as $notification) {
            Assert::assertSame('client_invitation', $notification['type']);
            Assert::assertSame((string) $this->registry->getInvitation($email, $clientAlias), $notification['invitationId']);
            Assert::assertStringContainsString('Log in', $notification['message']);
        }
    }

    /**
     * @Then the invitation integration event for :email should be processed exactly once
     */
    public function theInvitationIntegrationEventForShouldBeProcessedExactlyOnce(string $email): void
    {
        $outbox = $this->connection->fetchAllAssociative(
            "SELECT event_id, processed_at FROM shared.async_outbox WHERE event_name = :event_name AND payload ->> 'email' = :email",
            [
                'event_name' => ClientInvitationCreatedIntegrationEvent::class,
                'email' => (string) Email::fromString($email),
            ],
        );
        Assert::assertCount(1, $outbox);
        Assert::assertNotNull($outbox[0]['processed_at']);

        $consumptions = $this->connection->fetchAllAssociative(
            'SELECT status FROM shared.async_consumption WHERE event_id = :event_id AND subscriber = :subscriber',
            [
                'event_id' => $outbox[0]['event_id'],
                'subscriber' => SendClientInvitationNotificationSubscriber::class,
            ],
        );
        Assert::assertSame([['status' => 'processed']], $consumptions);
    }

    private function membership(string $email, string $clientAlias): ?ClientMemberDto
    {
        $user = $this->userQuery->findByEmail(Email::fromString($email));
        if (null === $user) {
            return null;
        }

        return $this->clientMemberQuery->findByClientAndUser($this->registry->getClient($clientAlias)->id, new Id($user->id));
    }

    /** @return list<array{type: string, invitationId: string, email: string, clientName: string, message: string}> */
    private function notifications(): array
    {
        if (!is_file($this->userNotificationLogPath)) {
            return [];
        }

        $lines = file($this->userNotificationLogPath, \FILE_IGNORE_NEW_LINES | \FILE_SKIP_EMPTY_LINES);
        Assert::assertNotFalse($lines);

        return array_map(static function (string $line): array {
            /** @var array{type: string, invitationId: string, email: string, clientName: string, message: string} $notification */
            $notification = json_decode($line, true, 512, \JSON_THROW_ON_ERROR);

            return $notification;
        }, $lines);
    }

    private function intValue(mixed $value): int
    {
        if (!\is_int($value) && !\is_string($value)) {
            throw new \RuntimeException('Expected an integer-compatible value.');
        }

        return (int) $value;
    }
}
