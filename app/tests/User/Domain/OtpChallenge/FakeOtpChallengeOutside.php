<?php

declare(strict_types=1);

namespace App\Tests\User\Domain\OtpChallenge;

use App\SharedKernel\Domain\Event\DomainEvent;
use App\SharedKernel\Domain\Event\DomainEventsRecorder;
use App\SharedKernel\Domain\ValueObject\DateTime;
use App\User\Domain\OtpChallenge\Outside\OtpChallengeOutsideInterface;

final class FakeOtpChallengeOutside implements OtpChallengeOutsideInterface
{
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

    public function generateCode(): string
    {
        return '123456';
    }

    public function hashCode(string $plainCode): string
    {
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
