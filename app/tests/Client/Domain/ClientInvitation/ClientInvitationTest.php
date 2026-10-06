<?php

declare(strict_types=1);

namespace App\Tests\Client\Domain\ClientInvitation;

use App\Client\Domain\ClientInvitation\ClientInvitation;
use App\Client\Domain\ClientInvitation\Event\ClientInvitationAccepted;
use App\Client\Domain\ClientInvitation\Event\ClientInvitationRejected;
use App\Client\Domain\ClientInvitation\Event\ClientInvitationRevoked;
use App\Client\Domain\ClientInvitation\Exception\ClientInvitationNotAddressedToUserException;
use App\Client\Domain\ClientInvitation\Exception\ClientInvitationNotPendingException;
use App\Client\Domain\ClientInvitation\Exception\ClientInvitationRoleNotAllowedException;
use App\SharedKernel\Domain\Event\InMemoryDomainEventsCollector;
use App\SharedKernel\Domain\ValueObject\Email;
use App\SharedKernel\Domain\ValueObject\Id;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ClientInvitationTest extends TestCase
{
    private InMemoryDomainEventsCollector $collector;
    private Id $invitationId;
    private Id $clientId;
    private Id $inviteeId;
    private Id $otherUserId;

    protected function setUp(): void
    {
        $this->collector = new InMemoryDomainEventsCollector();
        $this->invitationId = Id::new();
        $this->clientId = Id::new();
        $this->inviteeId = Id::new();
        $this->otherUserId = Id::new();
    }

    public function testPlatformRevokesAdminInvitationAndItCannotBeAccepted(): void
    {
        $invitation = $this->invitation('admin');
        $invitation->revokeByPlatformAdmin();
        $events = $this->collector->pull();
        self::assertCount(1, $events);
        self::assertInstanceOf(ClientInvitationRevoked::class, $events[0]);
        $this->expectException(ClientInvitationNotPendingException::class);
        $invitation->accept($this->inviteeId);
    }

    public function testPlatformCannotRevokeUserInvitation(): void
    {
        $this->expectException(ClientInvitationRoleNotAllowedException::class);
        $this->invitation('user')->revokeByPlatformAdmin();
    }

    public function testPlatformCannotRevokeRejectedAdminInvitation(): void
    {
        $invitation = $this->invitation('admin');
        $invitation->reject($this->inviteeId);
        $this->expectException(ClientInvitationNotPendingException::class);
        $invitation->revokeByPlatformAdmin();
    }

    public function testConstructionRejectsUnknownRole(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->invitation('owner');
    }

    #[DataProvider('invitedRoles')]
    public function testAcceptRecordsAcceptance(string $role): void
    {
        $invitation = $this->invitation($role);

        $invitation->accept($this->inviteeId);

        $events = $this->collector->pull();
        self::assertCount(1, $events);
        self::assertInstanceOf(ClientInvitationAccepted::class, $events[0]);
        $acceptance = $events[0];
        self::assertEquals($this->invitationId, $acceptance->clientInvitationId);
        self::assertEquals($this->clientId, $acceptance->clientId);
        self::assertEquals($this->inviteeId, $acceptance->userId);
        self::assertSame($role, $acceptance->role);
        self::assertSame('2026-09-29 10:00:00', $acceptance->occurredAt->toStorageString());
    }

    /** @return iterable<string, array{string}> */
    public static function invitedRoles(): iterable
    {
        yield 'user' => ['user'];
        yield 'admin' => ['admin'];
    }

    public function testRejectRecordsRejection(): void
    {
        $invitation = $this->invitation();

        $invitation->reject($this->inviteeId);

        $events = $this->collector->pull();
        self::assertCount(1, $events);
        self::assertInstanceOf(ClientInvitationRejected::class, $events[0]);
        self::assertEquals($this->invitationId, $events[0]->clientInvitationId);
        self::assertEquals($this->inviteeId, $events[0]->userId);
    }

    public function testClientAdminRevokesInvitationWithRoleUser(): void
    {
        $invitation = $this->invitation();

        $invitation->revokeByClientAdmin();

        $events = $this->collector->pull();
        self::assertCount(1, $events);
        self::assertInstanceOf(ClientInvitationRevoked::class, $events[0]);
        self::assertEquals($this->invitationId, $events[0]->clientInvitationId);
    }

    public function testClientAdminCannotRevokeInvitationWithRoleAdmin(): void
    {
        $invitation = $this->invitation('admin');

        try {
            $invitation->revokeByClientAdmin();
            self::fail('Expected revoking an admin invitation to be refused.');
        } catch (ClientInvitationRoleNotAllowedException) {
        }
        $eventsAfterRefusal = $this->collector->pull();
        self::assertSame([], $eventsAfterRefusal);

        // Still pending for the invitee.
        $invitation->reject($this->inviteeId);
        $events = $this->collector->pull();
        self::assertCount(1, $events);
        self::assertInstanceOf(ClientInvitationRejected::class, $events[0]);
    }

    #[DataProvider('inviteeActions')]
    public function testOnlyTheInviteeCanActOnTheInvitation(string $action): void
    {
        $invitation = $this->invitation();

        try {
            $this->act($invitation, $action, $this->otherUserId);
            self::fail('Expected an invitation addressed to someone else to be refused.');
        } catch (ClientInvitationNotAddressedToUserException) {
        }
        self::assertSame([], $this->collector->pull());

        // Unknown users cannot act either.
        $this->expectException(ClientInvitationNotAddressedToUserException::class);
        $this->act($invitation, $action, Id::new());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function inviteeActions(): iterable
    {
        yield 'accept' => ['accept'];
        yield 'reject' => ['reject'];
    }

    #[DataProvider('transitionsFromTerminalStatus')]
    public function testTransitionsAreAllowedOnlyFromPending(string $first, string $second): void
    {
        $invitation = $this->invitation();
        $this->act($invitation, $first, $this->inviteeId);
        $this->collector->pull();

        try {
            $this->act($invitation, $second, $this->inviteeId);
            self::fail(\sprintf('Expected %s after %s to be refused.', $second, $first));
        } catch (ClientInvitationNotPendingException) {
        }
        self::assertSame([], $this->collector->pull());
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function transitionsFromTerminalStatus(): iterable
    {
        foreach (['accept', 'reject', 'revoke'] as $first) {
            foreach (['accept', 'reject', 'revoke'] as $second) {
                yield $first . ' then ' . $second => [$first, $second];
            }
        }
    }

    private function invitation(string $role = 'user'): ClientInvitation
    {
        $invitation = new ClientInvitation(
            new FakeClientInvitationOutside($this->collector, userEmails: [
                (string) $this->inviteeId => Email::fromString('bob@example.com'),
                (string) $this->otherUserId => Email::fromString('eve@example.com'),
            ]),
            $this->invitationId,
            $this->clientId,
            Email::fromString('bob@example.com'),
            $role,
        );
        $this->collector->pull();

        return $invitation;
    }

    private function act(ClientInvitation $invitation, string $action, Id $userId): void
    {
        match ($action) {
            'accept' => $invitation->accept($userId),
            'reject' => $invitation->reject($userId),
            'revoke' => $invitation->revokeByClientAdmin(),
            default => throw new \LogicException($action),
        };
    }
}
