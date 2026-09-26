<?php

declare(strict_types=1);

namespace App\User\Domain\OtpChallenge\Outside;

use App\SharedKernel\Domain\Event\DomainEvent;
use App\SharedKernel\Domain\ValueObject\DateTime;
use App\SharedKernel\Domain\ValueObject\Email;

interface OtpChallengeOutsideInterface
{
    public function now(): DateTime;

    public function findLatestSentAtByEmail(Email $email): ?DateTime;

    public function findLatestSentAtByIp(string $ipAddress): ?DateTime;

    public function record(DomainEvent $event): void;

    public function generateCode(): string;

    public function hashCode(string $plainCode): string;

    public function verifyCode(string $plainCode, string $hash): bool;

    public function hashIp(string $ip): string;

    public function hashUserAgent(string $userAgent): string;
}
