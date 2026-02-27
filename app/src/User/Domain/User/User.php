<?php

declare(strict_types=1);

namespace App\User\Domain\User;

use App\SharedKernel\Domain\Attribute\OutsideField;
use App\SharedKernel\Domain\ValueObject\DateTime;
use App\SharedKernel\Domain\ValueObject\Email;
use App\SharedKernel\Domain\ValueObject\Id;
use App\User\Domain\User\Event\UserBlocked;
use App\User\Domain\User\Event\UserEmailChanged;
use App\User\Domain\User\Event\UserLoggedIn;
use App\User\Domain\User\Event\UserRegistered;
use App\User\Domain\User\Event\UserUnblocked;
use App\User\Domain\User\Outside\UserOutsideInterface;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'users', schema: '"user"')]
final class User
{
    private const string STATUS_ACTIVE = 'active';
    private const string STATUS_BLOCKED = 'blocked';

    #[OutsideField]
    private ?UserOutsideInterface $outside = null;

    #[ORM\Id]
    #[ORM\Column(type: 'domain_id')]
    private readonly Id $id;

    #[ORM\Column(type: 'domain_email', length: 255)]
    private Email $email;

    #[ORM\Column(length: 32)]
    private string $status;

    #[ORM\Column(type: 'domain_datetime')]
    private DateTime $createdAt;

    #[ORM\Column(type: 'domain_datetime', nullable: true)]
    private ?DateTime $lastLoginAt = null;

    #[ORM\Column(type: 'domain_datetime', nullable: true)]
    private ?DateTime $blockedAt = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $blockedReason = null;

    public function __construct(
        UserOutsideInterface $outside,
        Id $id,
        Email $email,
    ) {
        $this->outside = $outside;
        $this->id = $id;
        $this->email = $email;
        $this->status = self::STATUS_ACTIVE;
        $this->createdAt = $this->outside()->now();
        $this->outside()->record(new UserRegistered($this->id, (string) $this->email, $this->createdAt));
    }

    public function changeEmail(Email $newEmail): void
    {
        if ($this->email->equals($newEmail)) {
            return;
        }

        $oldEmail = $this->email;
        $this->email = $newEmail;
        $this->outside()->record(new UserEmailChanged($this->id, (string) $oldEmail, (string) $this->email, $this->outside()->now()));
    }

    public function markLoggedIn(): void
    {
        $this->lastLoginAt = $this->outside()->now();
        $this->outside()->record(new UserLoggedIn($this->id, $this->lastLoginAt));
    }

    public function block(string $reason): void
    {
        $reason = trim($reason);
        if ('' === $reason) {
            throw new \InvalidArgumentException('Block reason cannot be empty.');
        }
        if (self::STATUS_BLOCKED === $this->status) {
            return;
        }

        $this->status = self::STATUS_BLOCKED;
        $this->blockedAt = $this->outside()->now();
        $this->blockedReason = $reason;
        $this->outside()->record(new UserBlocked($this->id, $this->blockedReason, $this->blockedAt));
    }

    public function unblock(): void
    {
        if (self::STATUS_ACTIVE === $this->status) {
            return;
        }

        $this->status = self::STATUS_ACTIVE;
        $this->blockedAt = null;
        $this->blockedReason = null;
        $this->outside()->record(new UserUnblocked($this->id, $this->outside()->now()));
    }

    private function outside(): UserOutsideInterface
    {
        if (null === $this->outside) {
            throw new \LogicException('Outside not attached. Entity was not properly hydrated.');
        }

        return $this->outside;
    }
}
