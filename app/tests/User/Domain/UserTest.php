<?php

declare(strict_types=1);

namespace App\Tests\User\Domain;

use App\SharedKernel\Domain\Event\InMemoryDomainEventsCollector;
use App\SharedKernel\Domain\ValueObject\DateTime;
use App\SharedKernel\Domain\ValueObject\Email;
use App\SharedKernel\Domain\ValueObject\Id;
use App\User\Domain\User\Event\UserBlocked;
use App\User\Domain\User\Event\UserEmailChanged;
use App\User\Domain\User\Event\UserLoggedIn;
use App\User\Domain\User\Event\UserRegistered;
use App\User\Domain\User\Event\UserUnblocked;
use App\User\Domain\User\User;
use PHPUnit\Framework\TestCase;

final class UserTest extends TestCase
{
    // ========================================
    // Construction
    // ========================================

    public function testCannotCreateUserWithEmptyEmail(): void
    {
        // Then: exception is thrown by Email VO
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Email must be a valid address.');

        // When: attempting to create Email from empty string
        Email::fromString('');
    }

    public function testCannotCreateUserWithWhitespaceOnlyEmail(): void
    {
        // Then: exception is thrown by Email VO
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Email must be a valid address.');

        // When: attempting to create Email from whitespace
        Email::fromString('   ');
    }

    public function testConstructionRecordsUserRegisteredEvent(): void
    {
        // Given: valid user data
        $collector = new InMemoryDomainEventsCollector();
        $outside = new FakeUserOutside($collector);
        $id = Id::new();

        // When: creating a new user
        new User($outside, $id, Email::fromString('john@example.com'));

        // Then: UserRegistered event is recorded with status active
        $events = $collector->pull();
        $this->assertCount(1, $events);
        $this->assertInstanceOf(UserRegistered::class, $events[0]);
        $this->assertTrue($events[0]->userId->equals($id));
        $this->assertSame('john@example.com', $events[0]->email);
        $this->assertInstanceOf(DateTime::class, $events[0]->occurredAt);
    }

    public function testConstructionNormalizesEmail(): void
    {
        // Given: email with uppercase and whitespace
        $collector = new InMemoryDomainEventsCollector();
        $outside = new FakeUserOutside($collector);

        // When: creating a new user
        new User($outside, Id::new(), Email::fromString('  John@EXAMPLE.com  '));

        // Then: event contains normalized email
        $events = $collector->pull();
        $this->assertCount(1, $events);
        $this->assertInstanceOf(UserRegistered::class, $events[0]);
        $this->assertSame('john@example.com', $events[0]->email);
    }

    // ========================================
    // Change email
    // ========================================

    public function testCannotChangeEmailToEmpty(): void
    {
        // Then: exception is thrown by Email VO
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Email must be a valid address.');

        // When: attempting to create Email from empty string
        Email::fromString('');
    }

    public function testChangeEmailRecordsUserEmailChangedEvent(): void
    {
        // Given: an active user
        [$user, $collector, $id] = $this->createActiveUser();

        // When: changing email
        $user->changeEmail(Email::fromString('new@example.com'));

        // Then: UserEmailChanged event is recorded
        $events = $collector->pull();
        $this->assertCount(1, $events);
        $this->assertInstanceOf(UserEmailChanged::class, $events[0]);
        $this->assertTrue($events[0]->userId->equals($id));
        $this->assertSame('test@example.com', $events[0]->oldEmail);
        $this->assertSame('new@example.com', $events[0]->newEmail);
        $this->assertInstanceOf(DateTime::class, $events[0]->occurredAt);
    }

    public function testChangeEmailNormalizesNewEmail(): void
    {
        // Given: an active user
        [$user, $collector] = $this->createActiveUser();

        // When: changing email with uppercase and whitespace
        $user->changeEmail(Email::fromString('  NEW@EXAMPLE.COM  '));

        // Then: event contains normalized email
        $events = $collector->pull();
        $this->assertCount(1, $events);
        $this->assertInstanceOf(UserEmailChanged::class, $events[0]);
        $this->assertSame('new@example.com', $events[0]->newEmail);
    }

    public function testChangeEmailIsIdempotent(): void
    {
        // Given: a user with email "test@example.com"
        [$user, $collector] = $this->createActiveUser();

        // When: changing to the same email (with whitespace)
        $user->changeEmail(Email::fromString('  test@example.com  '));

        // Then: no events are recorded
        $events = $collector->pull();
        $this->assertCount(0, $events);
    }

    // ========================================
    // Mark logged in
    // ========================================

    public function testMarkLoggedInRecordsUserLoggedInEvent(): void
    {
        // Given: an active user
        [$user, $collector, $id] = $this->createActiveUser();

        // When: marking as logged in
        $user->markLoggedIn();

        // Then: UserLoggedIn event is recorded
        $events = $collector->pull();
        $this->assertCount(1, $events);
        $this->assertInstanceOf(UserLoggedIn::class, $events[0]);
        $this->assertTrue($events[0]->userId->equals($id));
        $this->assertInstanceOf(DateTime::class, $events[0]->occurredAt);
    }

    // ========================================
    // Block
    // ========================================

    public function testCannotBlockWithEmptyReason(): void
    {
        // Given: an active user
        [$user] = $this->createActiveUser();

        // Then: exception is thrown
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Block reason cannot be empty.');

        // When: blocking without reason
        $user->block('');
    }

    public function testCannotBlockWithWhitespaceOnlyReason(): void
    {
        // Given: an active user
        [$user] = $this->createActiveUser();

        // Then: exception is thrown
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Block reason cannot be empty.');

        // When: blocking with whitespace-only reason
        $user->block('   ');
    }

    public function testBlockRecordsUserBlockedEvent(): void
    {
        // Given: an active user
        [$user, $collector, $id] = $this->createActiveUser();

        // When: blocking
        $user->block('Violation of terms');

        // Then: UserBlocked event is recorded
        $events = $collector->pull();
        $this->assertCount(1, $events);
        $this->assertInstanceOf(UserBlocked::class, $events[0]);
        $this->assertTrue($events[0]->userId->equals($id));
        $this->assertSame('Violation of terms', $events[0]->reason);
        $this->assertInstanceOf(DateTime::class, $events[0]->occurredAt);
    }

    public function testBlockIsIdempotent(): void
    {
        // Given: an already blocked user
        [$user, $collector] = $this->createActiveUser();
        $user->block('First reason');
        $collector->pull(); // clear first event

        // When: blocking again
        $user->block('Second reason');

        // Then: no new events are recorded
        $events = $collector->pull();
        $this->assertCount(0, $events);
    }

    // ========================================
    // Unblock
    // ========================================

    public function testUnblockRecordsUserUnblockedEvent(): void
    {
        // Given: a blocked user
        [$user, $collector, $id] = $this->createActiveUser();
        $user->block('Some reason');
        $collector->pull(); // clear block event

        // When: unblocking
        $user->unblock();

        // Then: UserUnblocked event is recorded
        $events = $collector->pull();
        $this->assertCount(1, $events);
        $this->assertInstanceOf(UserUnblocked::class, $events[0]);
        $this->assertTrue($events[0]->userId->equals($id));
        $this->assertInstanceOf(DateTime::class, $events[0]->occurredAt);
    }

    public function testUnblockIsIdempotent(): void
    {
        // Given: an active user (not blocked)
        [$user, $collector] = $this->createActiveUser();

        // When: unblocking an already active user
        $user->unblock();

        // Then: no events are recorded
        $events = $collector->pull();
        $this->assertCount(0, $events);
    }

    // ========================================
    // Helpers
    // ========================================

    /**
     * @return array{User, InMemoryDomainEventsCollector, Id}
     */
    private function createActiveUser(): array
    {
        $collector = new InMemoryDomainEventsCollector();
        $id = Id::new();
        $user = new User(
            new FakeUserOutside($collector),
            $id,
            Email::fromString('test@example.com'),
        );
        $collector->pull(); // clear UserRegistered event from construction

        return [$user, $collector, $id];
    }
}
