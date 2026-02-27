<?php

declare(strict_types=1);

namespace App\User\Domain\OtpChallenge;

use App\SharedKernel\Domain\Attribute\OutsideField;
use App\SharedKernel\Domain\ValueObject\DateTime;
use App\SharedKernel\Domain\ValueObject\Email;
use App\SharedKernel\Domain\ValueObject\Id;
use App\User\Domain\OtpChallenge\Event\OtpChallengeVerified;
use App\User\Domain\OtpChallenge\Outside\OtpChallengeOutsideInterface;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'otp_challenges', schema: '"user"')]
final class OtpChallenge
{
    private const int TTL_MINUTES = 10;

    #[OutsideField]
    private ?OtpChallengeOutsideInterface $outside = null;

    #[ORM\Id]
    #[ORM\Column(type: 'domain_id')]
    private readonly Id $id;

    #[ORM\Column(type: 'domain_email', length: 255)]
    private Email $email;

    #[ORM\Column(length: 255)]
    private string $codeHash;

    #[ORM\Column(type: 'domain_datetime')]
    private DateTime $expiresAt;

    #[ORM\Column(type: 'integer')]
    private int $attempts;

    #[ORM\Column(type: 'domain_datetime')]
    private DateTime $lastSentAt;

    #[ORM\Column(type: 'integer')]
    private int $sentCount;

    #[ORM\Column(type: 'domain_datetime', nullable: true)]
    private ?DateTime $consumedAt = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $ipHash = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $userAgentHash = null;

    public function __construct(
        OtpChallengeOutsideInterface $outside,
        Id $id,
        Email $email,
        string $plainCode,
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ) {
        $this->outside = $outside;
        $this->id = $id;
        $this->email = $email;

        $now = $this->outside()->now();
        $this->codeHash = $this->outside()->hashCode($plainCode);
        $this->expiresAt = new DateTime($now->value->modify(\sprintf('+%d minutes', self::TTL_MINUTES)));
        $this->attempts = 0;
        $this->lastSentAt = $now;
        $this->sentCount = 1;

        if (null !== $ipAddress) {
            $this->ipHash = $this->outside()->hashIp($ipAddress);
        }
        if (null !== $userAgent) {
            $this->userAgentHash = $this->outside()->hashUserAgent($userAgent);
        }
    }

    public function verify(string $plainCode, int $maxAttempts): bool
    {
        if (null !== $this->consumedAt) {
            return false;
        }

        $now = $this->outside()->now();

        if ($now->value > $this->expiresAt->value) {
            return false;
        }
        if ($this->attempts >= $maxAttempts) {
            return false;
        }
        if (!$this->outside()->verifyCode($plainCode, $this->codeHash)) {
            ++$this->attempts;

            return false;
        }

        $this->consumedAt = $now;
        $this->outside()->record(new OtpChallengeVerified($this->id, $this->email, $now));

        return true;
    }

    private function outside(): OtpChallengeOutsideInterface
    {
        if (null === $this->outside) {
            throw new \LogicException('Outside not attached. Entity was not properly hydrated.');
        }

        return $this->outside;
    }
}
