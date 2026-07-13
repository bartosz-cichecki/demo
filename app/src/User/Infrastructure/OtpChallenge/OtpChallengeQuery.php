<?php

declare(strict_types=1);

namespace App\User\Infrastructure\OtpChallenge;

use App\SharedKernel\Domain\ValueObject\Email;
use App\User\Application\OtpChallenge\Query\Dto\OtpChallengeDto;
use App\User\Application\OtpChallenge\Query\OtpChallengeQueryInterface;
use Doctrine\DBAL\Connection;

final readonly class OtpChallengeQuery implements OtpChallengeQueryInterface
{
    public function __construct(
        private Connection $connection,
    ) {
    }

    public function findLatestByEmail(Email $email): ?OtpChallengeDto
    {
        $row = $this->connection->fetchAssociative(
            'SELECT attempts, consumed_at FROM "user".otp_challenges WHERE email = :email ORDER BY last_sent_at DESC LIMIT 1',
            ['email' => (string) $email],
        );

        if (false === $row) {
            return null;
        }

        \assert(\is_int($row['attempts']) || \is_string($row['attempts']));
        \assert(null === $row['consumed_at'] || \is_string($row['consumed_at']));

        return new OtpChallengeDto(
            attempts: (int) $row['attempts'],
            consumedAt: $row['consumed_at'],
        );
    }
}
