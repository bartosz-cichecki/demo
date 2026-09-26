<?php

declare(strict_types=1);

namespace App\Tests\User\Domain\OtpChallenge;

use App\SharedKernel\Domain\Event\InMemoryDomainEventsCollector;
use App\SharedKernel\Domain\ValueObject\DateTime;
use App\SharedKernel\Domain\ValueObject\Email;
use App\SharedKernel\Domain\ValueObject\Id;
use App\User\Domain\OtpChallenge\Factory\OtpChallengeFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class OtpChallengeFactoryTest extends TestCase
{
    #[DataProvider('cooldownCases')]
    public function testCooldownBeforeGeneratingCodeOrCreatingChallenge(
        ?int $emailSecondsAgo,
        ?int $ipSecondsAgo,
        ?string $ipAddress,
        bool $allowed,
    ): void {
        $collector = new InMemoryDomainEventsCollector();
        $outside = new FakeOtpChallengeOutside($collector);
        $email = Email::fromString('otp@example.com');

        if (null !== $emailSecondsAgo) {
            $outside->lastSentAtByEmail[(string) $email] = new DateTime($outside->now()->value->modify(\sprintf('-%d seconds', $emailSecondsAgo)));
        }
        if (null !== $ipSecondsAgo) {
            $outside->lastSentAtByIp['192.0.2.1'] = new DateTime($outside->now()->value->modify(\sprintf('-%d seconds', $ipSecondsAgo)));
        }

        $factory = new OtpChallengeFactory($outside);
        $issue = $factory->issue(Id::new(), $email, $ipAddress, null);

        $this->assertSame($allowed, null !== $issue);
        $this->assertSame($allowed ? 1 : 0, $outside->generatedCodes);
        $this->assertSame($allowed ? 1 : 0, $outside->hashedCodes);
        $this->assertSame(null === $ipAddress ? 0 : 1, $outside->ipLookups);
        $this->assertSame([], $collector->pull());

        if (null !== $issue) {
            $this->assertTrue($issue->challenge->verify($issue->plainCode));
        }
    }

    /** @return iterable<string, array{?int, ?int, ?string, bool}> */
    public static function cooldownCases(): iterable
    {
        yield 'first request' => [null, null, '192.0.2.1', true];
        yield 'immediate repeat' => [0, 0, '192.0.2.1', false];
        yield 'before 60 seconds' => [59, 59, '192.0.2.1', false];
        yield 'exactly 60 seconds' => [60, 60, '192.0.2.1', true];
        yield 'after 60 seconds' => [61, 61, '192.0.2.1', true];
        yield 'email blocks with new IP' => [59, null, '192.0.2.2', false];
        yield 'email blocks with expired IP cooldown' => [59, 60, '192.0.2.1', false];
        yield 'IP blocks with new email' => [null, 59, '192.0.2.1', false];
        yield 'IP blocks with expired email cooldown' => [60, 59, '192.0.2.1', false];
        yield 'email boundary without IP history' => [60, null, '192.0.2.1', true];
        yield 'IP boundary without email history' => [null, 60, '192.0.2.1', true];
        yield 'first request without IP' => [null, null, null, true];
        yield 'without IP ignores IP cooldown' => [null, 0, null, true];
        yield 'without IP email still blocks' => [59, null, null, false];
        yield 'without IP email boundary' => [60, null, null, true];
    }
}
