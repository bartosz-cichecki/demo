<?php

declare(strict_types=1);

namespace App\Tests\User\Infrastructure\OtpChallenge;

use App\SharedKernel\Domain\Event\DomainEvent;
use App\SharedKernel\Domain\ValueObject\DateTime;
use App\User\Domain\OtpChallenge\Outside\OtpChallengeOutsideInterface;

final readonly class DeterministicCodeOtpChallengeOutside implements OtpChallengeOutsideInterface
{
    public function __construct(
        private OtpChallengeOutsideInterface $inner,
    ) {
    }

    public function now(): DateTime
    {
        return $this->inner->now();
    }

    public function record(DomainEvent $event): void
    {
        $this->inner->record($event);
    }

    public function generateCode(): string
    {
        return '123456';
    }

    public function hashCode(string $plainCode): string
    {
        return $this->inner->hashCode($plainCode);
    }

    public function verifyCode(string $plainCode, string $hash): bool
    {
        return $this->inner->verifyCode($plainCode, $hash);
    }

    public function hashIp(string $ip): string
    {
        return $this->inner->hashIp($ip);
    }

    public function hashUserAgent(string $userAgent): string
    {
        return $this->inner->hashUserAgent($userAgent);
    }
}
