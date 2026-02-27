<?php

declare(strict_types=1);

namespace App\Tests\User\Domain\OtpChallenge;

use App\SharedKernel\Domain\Event\InMemoryDomainEventsCollector;
use App\SharedKernel\Domain\ValueObject\Email;
use App\SharedKernel\Domain\ValueObject\Id;
use App\User\Domain\OtpChallenge\Event\OtpChallengeVerified;
use App\User\Domain\OtpChallenge\OtpChallenge;
use PHPUnit\Framework\TestCase;

final class OtpChallengeTest extends TestCase
{
    // ========================================
    // Construction
    // ========================================

    public function testConstructionNormalizesEmail(): void
    {
        [$challenge, $collector, , $outside] = $this->createChallenge('  John@EXAMPLE.com  ');

        // verify with correct code to check the challenge works — event carries normalized email
        $result = $challenge->verify('123456', 3);

        $this->assertTrue($result);
        $events = $collector->pull();
        $this->assertCount(1, $events);
        $this->assertInstanceOf(OtpChallengeVerified::class, $events[0]);
        $this->assertSame('john@example.com', (string) $events[0]->email);
    }

    public function testCannotCreateWithEmptyEmail(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Email must be a valid address.');

        Email::fromString('');
    }

    // ========================================
    // Verify — success
    // ========================================

    public function testVerifySuccessRecordsEvent(): void
    {
        [$challenge, $collector, $id] = $this->createChallenge();

        $result = $challenge->verify('123456', 3);

        $this->assertTrue($result);
        $events = $collector->pull();
        $this->assertCount(1, $events);
        $this->assertInstanceOf(OtpChallengeVerified::class, $events[0]);
        $this->assertTrue($events[0]->otpChallengeId->equals($id));
        $this->assertSame('test@example.com', (string) $events[0]->email);
    }

    // ========================================
    // Verify — wrong code
    // ========================================

    public function testVerifyWrongCodeReturnsFalse(): void
    {
        [$challenge, $collector] = $this->createChallenge();

        $result = $challenge->verify('000000', 3);

        $this->assertFalse($result);
        $this->assertCount(0, $collector->pull());
    }

    public function testVerifyWrongCodeIncrementsAttempts(): void
    {
        [$challenge] = $this->createChallenge();

        // Use 2 wrong attempts
        $challenge->verify('000000', 3);
        $challenge->verify('000000', 3);

        // 3rd wrong attempt — still under limit, but increments to 3
        $challenge->verify('000000', 3);

        // Now at maxAttempts — even correct code should fail
        $result = $challenge->verify('123456', 3);
        $this->assertFalse($result);
    }

    // ========================================
    // Verify — max attempts reached
    // ========================================

    public function testVerifyFailsWhenMaxAttemptsReached(): void
    {
        [$challenge] = $this->createChallenge();

        // Exhaust attempts
        $challenge->verify('000000', 1);

        // Correct code but too late
        $result = $challenge->verify('123456', 1);
        $this->assertFalse($result);
    }

    // ========================================
    // Verify — expired
    // ========================================

    public function testVerifyFailsWhenExpired(): void
    {
        [$challenge, $collector, , $outside] = $this->createChallenge();

        $outside->advanceTime('+11 minutes');

        $result = $challenge->verify('123456', 3);

        $this->assertFalse($result);
        $this->assertCount(0, $collector->pull());
    }

    // ========================================
    // Verify — already consumed
    // ========================================

    public function testVerifyFailsWhenAlreadyConsumed(): void
    {
        [$challenge, $collector] = $this->createChallenge();

        $challenge->verify('123456', 3);
        $collector->pull(); // clear first event

        $result = $challenge->verify('123456', 3);

        $this->assertFalse($result);
        $this->assertCount(0, $collector->pull());
    }

    // ========================================
    // Helpers
    // ========================================

    /**
     * @return array{OtpChallenge, InMemoryDomainEventsCollector, Id, FakeOtpChallengeOutside}
     */
    private function createChallenge(string $email = 'test@example.com'): array
    {
        $collector = new InMemoryDomainEventsCollector();
        $outside = new FakeOtpChallengeOutside($collector);
        $id = Id::new();
        $challenge = new OtpChallenge($outside, $id, Email::fromString($email), '123456');

        return [$challenge, $collector, $id, $outside];
    }
}
