<?php

declare(strict_types=1);

namespace App\User\Infrastructure\OtpChallenge\RateLimit;

use App\SharedKernel\Domain\Clock\ClockInterface;
use App\SharedKernel\Domain\ValueObject\Email;
use App\User\Application\OtpChallenge\Query\Dto\OtpRateLimitDto;
use App\User\Application\OtpChallenge\Query\OtpRateLimitQueryInterface;
use App\User\Application\OtpChallenge\Service\ValueHasherServiceInterface;
use Doctrine\DBAL\Connection;

final readonly class OtpRateLimitQuery implements OtpRateLimitQueryInterface
{
    private const int COOLDOWN_SECONDS = 60;

    public function __construct(
        private Connection $connection,
        private ValueHasherServiceInterface $valueHasher,
        private ClockInterface $clock,
    ) {
    }

    public function check(Email $email, ?string $ipAddress): OtpRateLimitDto
    {
        $emailLastSentAt = $this->findLatestSentAtByEmail($email);
        $ipLastSentAt = null;

        if (null !== $ipAddress) {
            $ipLastSentAt = $this->findLatestSentAtByIpHash($this->valueHasher->hash($ipAddress));
        }

        $now = $this->clock->now()->value;
        $blockedUntil = $this->resolveBlockedUntil($emailLastSentAt, $ipLastSentAt);

        if (null === $blockedUntil || $blockedUntil <= $now) {
            return new OtpRateLimitDto(true, 0);
        }

        return new OtpRateLimitDto(
            false,
            $blockedUntil->getTimestamp() - $now->getTimestamp(),
        );
    }

    private function findLatestSentAtByEmail(Email $email): ?\DateTimeImmutable
    {
        $value = $this->connection->fetchOne(
            'SELECT last_sent_at FROM "user".otp_challenges WHERE email = :email ORDER BY last_sent_at DESC LIMIT 1',
            ['email' => (string) $email],
        );

        if (!\is_string($value)) {
            return null;
        }

        return new \DateTimeImmutable($value);
    }

    private function findLatestSentAtByIpHash(string $ipHash): ?\DateTimeImmutable
    {
        $value = $this->connection->fetchOne(
            'SELECT last_sent_at FROM "user".otp_challenges WHERE ip_hash = :ip_hash ORDER BY last_sent_at DESC LIMIT 1',
            ['ip_hash' => $ipHash],
        );

        if (!\is_string($value)) {
            return null;
        }

        return new \DateTimeImmutable($value);
    }

    private function resolveBlockedUntil(?\DateTimeImmutable $emailLastSentAt, ?\DateTimeImmutable $ipLastSentAt): ?\DateTimeImmutable
    {
        $reference = $emailLastSentAt;

        if (null !== $ipLastSentAt && (null === $reference || $ipLastSentAt > $reference)) {
            $reference = $ipLastSentAt;
        }

        if (null === $reference) {
            return null;
        }

        return $reference->modify('+' . self::COOLDOWN_SECONDS . ' seconds');
    }
}
