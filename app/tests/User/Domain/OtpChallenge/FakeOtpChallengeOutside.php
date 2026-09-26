<?php

declare(strict_types=1);

namespace App\Tests\User\Domain\OtpChallenge;

use App\SharedKernel\Domain\Event\DomainEvent;
use App\SharedKernel\Domain\Event\DomainEventsRecorder;
use App\SharedKernel\Domain\ValueObject\DateTime;
use App\SharedKernel\Domain\ValueObject\Email;
use App\User\Domain\OtpChallenge\Outside\OtpChallengeOutsideInterface;

final class FakeOtpChallengeOutside implements OtpChallengeOutsideInterface
{
    /** @var array<string, DateTime> */
    public array $lastSentAtByEmail = [];

    /** @var array<string, DateTime> */
    public array $lastSentAtByIp = [];

    public int $generatedCodes = 0;
    public int $hashedCodes = 0;
    public int $ipLookups = 0;

    public function __construct(
        private readonly DomainEventsRecorder $recorder,
        private \DateTimeImmutable $fixedTime = new \DateTimeImmutable('2024-01-15 10:00:00'),
    ) {
    }

    public function advanceTime(string $modify): void
    {
        $this->fixedTime = $this->fixedTime->modify($modify);
    }

    public function now(): DateTime
    {
        return new DateTime($this->fixedTime);
    }

    public function record(DomainEvent $event): void
    {
        $this->recorder->record($event);
    }

    public function findLatestSentAtByEmail(Email $email): ?DateTime
    {
        return $this->lastSentAtByEmail[(string) $email] ?? null;
    }

    public function findLatestSentAtByIp(string $ipAddress): ?DateTime
    {
        ++$this->ipLookups;

        return $this->lastSentAtByIp[$ipAddress] ?? null;
    }

    public function generateCode(): string
    {
        ++$this->generatedCodes;

        return '123456';
    }

    public function hashCode(string $plainCode): string
    {
        ++$this->hashedCodes;

        return 'hashed:' . $plainCode;
    }

    public function verifyCode(string $plainCode, string $hash): bool
    {
        return $hash === 'hashed:' . $plainCode;
    }

    public function hashIp(string $ip): string
    {
        return 'ip:' . $ip;
    }

    public function hashUserAgent(string $userAgent): string
    {
        return 'ua:' . $userAgent;
    }
}
