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
use App\Client\Domain\ClientMember\Event\ClientMemberCreated;
use App\Client\Domain\ClientMember\Factory\ClientMemberFactory;
use App\Client\Domain\ClientMember\Repository\Exception\ClientMemberAlreadyExistsException;
use App\SharedKernel\Domain\Event\InMemoryDomainEventsCollector;
use App\SharedKernel\Domain\ValueObject\Email;
use App\SharedKernel\Domain\ValueObject\Id;
use App\Tests\Client\Domain\ClientMember\FakeClientMemberOutside;
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

    public function testConstructionRejectsUnknownRole(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->invitation('owner');
    }

    public function testAcceptCreatesMembershipWithInvitedRoleAndRecordsAcceptance(): void
    {
        $invitation = $this->invitation();
        $memberId = Id::new();
        $memberOutside = new FakeClientMemberOutside($this->collector);

        $invitation->accept($this->inviteeId, $memberId, new ClientMemberFactory($memberOutside));

        self::assertSame([[$this->clientId, $this->inviteeId]], $memberOutside->membershipLookups);
        $events = $this->collector->pull();
        self::assertCount(2, $events);
        self::assertInstanceOf(ClientMemberCreated::class, $events[0]);
        self::assertEquals($memberId, $events[0]->clientMemberId);
        self::assertEquals($this->clientId, $events[0]->clientId);
        self::assertEquals($this->inviteeId, $events[0]->userId);
        self::assertSame(['user'], $events[0]->roles);
        self::assertInstanceOf(ClientInvitationAccepted::class, $events[1]);
        self::assertEquals($this->invitationId, $events[1]->clientInvitationId);
        self::assertEquals($this->clientId, $events[1]->clientId);
        self::assertEquals($this->inviteeId, $events[1]->userId);
        self::assertSame('user', $events[1]->role);
    }

    public function testAcceptWithExistingMembershipLeavesInvitationPending(): void
    {
        $invitation = $this->invitation();

        try {
            $invitation->accept($this->inviteeId, Id::new(), new ClientMemberFactory(new FakeClientMemberOutside($this->collector, membershipExists: true)));
            self::fail('Expected an existing membership to be refused.');
        } catch (ClientMemberAlreadyExistsException) {
        }
        $eventsAfterRefusal = $this->collector->pull();
        self::assertSame([], $eventsAfterRefusal);

        // Still pending: the admin can revoke it.
        $invitation->revokeByClientAdmin();
        $events = $this->collector->pull();
        self::assertCount(1, $events);
        self::assertInstanceOf(ClientInvitationRevoked::class, $events[0]);
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
            'accept' => $invitation->accept($userId, Id::new(), new ClientMemberFactory(new FakeClientMemberOutside($this->collector))),
            'reject' => $invitation->reject($userId),
            'revoke' => $invitation->revokeByClientAdmin(),
            default => throw new \LogicException($action),
        };
    }
}
