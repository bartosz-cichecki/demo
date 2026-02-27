<?php

declare(strict_types=1);

namespace App\User\Infrastructure\OtpChallenge;

use App\SharedKernel\Domain\Event\DomainEvent;
use App\SharedKernel\Domain\Event\DomainEventsRecorder;
use App\SharedKernel\Domain\ValueObject\DateTime;
use App\SharedKernel\Infrastructure\Outside\Attribute\AsOutsideFor;
use App\User\Application\OtpChallenge\Service\ValueHasherServiceInterface;
use App\User\Domain\OtpChallenge\Outside\OtpChallengeOutsideInterface;

#[AsOutsideFor(OtpChallengeOutsideInterface::class)]
final readonly class OtpChallengeOutside implements OtpChallengeOutsideInterface
{
    public function __construct(
        private DomainEventsRecorder $domainEventsRecorder,
        private ValueHasherServiceInterface $valueHasher,
    ) {
    }

    public function now(): DateTime
    {
        return DateTime::now();
    }

    public function record(DomainEvent $event): void
    {
        $this->domainEventsRecorder->record($event);
    }

    public function generateCode(): string
    {
        return str_pad((string) random_int(0, 999999), 6, '0', \STR_PAD_LEFT);
    }

    public function hashCode(string $plainCode): string
    {
        return password_hash($plainCode, \PASSWORD_BCRYPT);
    }

    public function verifyCode(string $plainCode, string $hash): bool
    {
        return password_verify($plainCode, $hash);
    }

    public function hashIp(string $ip): string
    {
        return $this->valueHasher->hash($ip);
    }

    public function hashUserAgent(string $userAgent): string
    {
        return $this->valueHasher->hash($userAgent);
    }
}
