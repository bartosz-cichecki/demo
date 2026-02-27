<?php

declare(strict_types=1);

namespace App\User\Infrastructure\OtpChallenge;

use App\SharedKernel\Domain\ValueObject\Email;
use App\User\Domain\OtpChallenge\OtpChallenge;
use App\User\Domain\OtpChallenge\Repository\OtpChallengeRepositoryInterface;
use Doctrine\ORM\EntityManagerInterface;

final readonly class OtpChallengeRepository implements OtpChallengeRepositoryInterface
{
    public function __construct(
        private EntityManagerInterface $em,
    ) {
    }

    public function create(OtpChallenge $otpChallenge): void
    {
        $this->em->persist($otpChallenge);
    }

    public function findLatestByEmail(Email $email): ?OtpChallenge
    {
        return $this->em->getRepository(OtpChallenge::class)->findOneBy(
            ['email' => $email],
            ['lastSentAt' => 'DESC'],
        );
    }
}
