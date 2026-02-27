<?php

declare(strict_types=1);

namespace App\Tests\Client\Domain\ClientMember;

use App\Client\Domain\ClientMember\ClientMember;
use App\Client\Domain\ClientMember\Event\ClientMemberCreated;
use App\Client\Domain\ClientMember\Event\ClientMemberRolesChanged;
use App\Client\Domain\ClientMember\Event\ClientMemberSuspended;
use App\Client\Domain\ClientMember\Event\ClientMemberUnsuspended;
use App\SharedKernel\Domain\Event\InMemoryDomainEventsCollector;
use App\SharedKernel\Domain\ValueObject\DateTime;
use App\SharedKernel\Domain\ValueObject\Id;
use PHPUnit\Framework\TestCase;

final class ClientMemberTest extends TestCase
{
    // ========================================
    // Construction
    // ========================================

    public function testConstructionCreatesActiveMemberWithNormalizedRoles(): void
    {
        // Given: valid data with duplicate and unsorted roles
        $collector = new InMemoryDomainEventsCollector();
        $outside = new FakeClientMemberOutside($collector);
        $id = Id::new();
        $clientId = Id::new();
        $userId = Id::new();

        // When: creating a new member
        new ClientMember($outside, $id, $clientId, $userId, ['admin', 'user', 'user']);

        // Then: ClientMemberCreated event is recorded with normalized roles
        $events = $collector->pull();
        $this->assertCount(1, $events);
        $this->assertInstanceOf(ClientMemberCreated::class, $events[0]);
        $this->assertTrue($events[0]->clientMemberId->equals($id));
        $this->assertTrue($events[0]->clientId->equals($clientId));
        $this->assertTrue($events[0]->userId->equals($userId));
        $this->assertSame(['admin', 'user'], $events[0]->roles);
        $this->assertInstanceOf(DateTime::class, $events[0]->occurredAt);
    }

    public function testCannotCreateMemberWithUnknownRole(): void
    {
        // Given: unknown role
        $collector = new InMemoryDomainEventsCollector();
        $outside = new FakeClientMemberOutside($collector);

        // Then: exception is thrown
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown role: unknown_role');

        // When: attempting to create member
        new ClientMember($outside, Id::new(), Id::new(), Id::new(), ['unknown_role']);
    }

    public function testCanCreateMemberWithAllValidRoles(): void
    {
        // Given: all valid roles
        $collector = new InMemoryDomainEventsCollector();
        $outside = new FakeClientMemberOutside($collector);

        // When: creating member with all valid roles
        new ClientMember(
            $outside,
            Id::new(),
            Id::new(),
            Id::new(),
            ['admin', 'user'],
        );

        // Then: no exception and event is recorded
        $events = $collector->pull();
        $this->assertCount(1, $events);
        $this->assertInstanceOf(ClientMemberCreated::class, $events[0]);
        $this->assertSame(['admin', 'user'], $events[0]->roles);
    }

    // ========================================
    // Replace roles
    // ========================================

    public function testReplaceRolesRecordsEvent(): void
    {
        // Given: an active member
        [$member, $collector, $id] = $this->createActiveMember();

        // When: replacing roles
        $member->replaceRoles(['admin']);

        // Then: ClientMemberRolesChanged event is recorded
        $events = $collector->pull();
        $this->assertCount(1, $events);
        $this->assertInstanceOf(ClientMemberRolesChanged::class, $events[0]);
        $this->assertTrue($events[0]->clientMemberId->equals($id));
        $this->assertSame(['user'], $events[0]->oldRoles);
        $this->assertSame(['admin'], $events[0]->newRoles);
    }

    public function testReplaceRolesIsIdempotent(): void
    {
        // Given: a member with role 'user'
        [$member, $collector] = $this->createActiveMember();

        // When: replacing with same role
        $member->replaceRoles(['user']);

        // Then: no events are recorded
        $events = $collector->pull();
        $this->assertCount(0, $events);
    }

    public function testReplaceRolesValidatesNewRoles(): void
    {
        // Given: an active member
        [$member] = $this->createActiveMember();

        // Then: exception is thrown
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown role: invalid');

        // When: replacing with invalid role
        $member->replaceRoles(['invalid']);
    }

    // ========================================
    // Add roles
    // ========================================

    public function testAddRolesRecordsEvent(): void
    {
        // Given: an active member with role 'user'
        [$member, $collector, $id] = $this->createActiveMember();

        // When: adding roles
        $member->addRoles(['admin']);

        // Then: ClientMemberRolesChanged event is recorded
        $events = $collector->pull();
        $this->assertCount(1, $events);
        $this->assertInstanceOf(ClientMemberRolesChanged::class, $events[0]);
        $this->assertSame(['user'], $events[0]->oldRoles);
        $this->assertSame(['admin', 'user'], $events[0]->newRoles);
    }

    public function testAddRolesIsIdempotent(): void
    {
        // Given: a member with role 'user'
        [$member, $collector] = $this->createActiveMember();

        // When: adding same role
        $member->addRoles(['user']);

        // Then: no events are recorded
        $events = $collector->pull();
        $this->assertCount(0, $events);
    }

    // ========================================
    // Remove roles
    // ========================================

    public function testRemoveRolesRecordsEvent(): void
    {
        // Given: a member with multiple roles
        $collector = new InMemoryDomainEventsCollector();
        $id = Id::new();
        $member = new ClientMember(
            new FakeClientMemberOutside($collector),
            $id,
            Id::new(),
            Id::new(),
            ['user', 'admin'],
        );
        $collector->pull(); // clear creation event

        // When: removing a role
        $member->removeRoles(['admin']);

        // Then: ClientMemberRolesChanged event is recorded
        $events = $collector->pull();
        $this->assertCount(1, $events);
        $this->assertInstanceOf(ClientMemberRolesChanged::class, $events[0]);
        $this->assertSame(['admin', 'user'], $events[0]->oldRoles);
        $this->assertSame(['user'], $events[0]->newRoles);
    }

    public function testRemoveRolesIsIdempotent(): void
    {
        // Given: a member with role 'user'
        [$member, $collector] = $this->createActiveMember();

        // When: removing non-existent role
        $member->removeRoles(['admin']);

        // Then: no events are recorded
        $events = $collector->pull();
        $this->assertCount(0, $events);
    }

    // ========================================
    // Suspend
    // ========================================

    public function testSuspendRecordsEvent(): void
    {
        // Given: an active member
        [$member, $collector, $id] = $this->createActiveMember();

        // When: suspending
        $member->suspend();

        // Then: ClientMemberSuspended event is recorded
        $events = $collector->pull();
        $this->assertCount(1, $events);
        $this->assertInstanceOf(ClientMemberSuspended::class, $events[0]);
        $this->assertTrue($events[0]->clientMemberId->equals($id));
    }

    public function testSuspendIsIdempotent(): void
    {
        // Given: an already suspended member
        [$member, $collector] = $this->createActiveMember();
        $member->suspend();
        $collector->pull(); // clear first event

        // When: suspending again
        $member->suspend();

        // Then: no new events are recorded
        $events = $collector->pull();
        $this->assertCount(0, $events);
    }

    // ========================================
    // Unsuspend
    // ========================================

    public function testUnsuspendRecordsEvent(): void
    {
        // Given: a suspended member
        [$member, $collector, $id] = $this->createActiveMember();
        $member->suspend();
        $collector->pull(); // clear suspend event

        // When: unsuspending
        $member->unsuspend();

        // Then: ClientMemberUnsuspended event is recorded
        $events = $collector->pull();
        $this->assertCount(1, $events);
        $this->assertInstanceOf(ClientMemberUnsuspended::class, $events[0]);
        $this->assertTrue($events[0]->clientMemberId->equals($id));
    }

    public function testUnsuspendIsIdempotent(): void
    {
        // Given: an active member
        [$member, $collector] = $this->createActiveMember();

        // When: unsuspending (already active)
        $member->unsuspend();

        // Then: no events are recorded
        $events = $collector->pull();
        $this->assertCount(0, $events);
    }

    // ========================================
    // Helpers
    // ========================================

    /**
     * @return array{ClientMember, InMemoryDomainEventsCollector, Id}
     */
    private function createActiveMember(): array
    {
        $collector = new InMemoryDomainEventsCollector();
        $id = Id::new();
        $member = new ClientMember(
            new FakeClientMemberOutside($collector),
            $id,
            Id::new(),
            Id::new(),
            ['user'],
        );
        $collector->pull(); // clear ClientMemberCreated event

        return [$member, $collector, $id];
    }
}
