<?php

declare(strict_types=1);

namespace App\Client\Domain\ClientMember;

use App\Client\Domain\ClientMember\Event\ClientMemberCreated;
use App\Client\Domain\ClientMember\Event\ClientMemberRolesChanged;
use App\Client\Domain\ClientMember\Event\ClientMemberSuspended;
use App\Client\Domain\ClientMember\Event\ClientMemberUnsuspended;
use App\Client\Domain\ClientMember\Outside\ClientMemberOutsideInterface;
use App\SharedKernel\Domain\Attribute\OutsideField;
use App\SharedKernel\Domain\ValueObject\DateTime;
use App\SharedKernel\Domain\ValueObject\Id;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'client_memberships', schema: 'client')]
final class ClientMember
{
    public const string ROLE_ADMIN = 'admin';
    public const string ROLE_USER = 'user';

    public const array ALLOWED_ROLES = [
        self::ROLE_ADMIN,
        self::ROLE_USER,
    ];

    public const string STATUS_ACTIVE = 'active';
    public const string STATUS_SUSPENDED = 'suspended';

    #[OutsideField]
    private ?ClientMemberOutsideInterface $outside = null;

    #[ORM\Id]
    #[ORM\Column(type: 'domain_id')]
    private readonly Id $id;

    #[ORM\Column(type: 'domain_id')]
    private readonly Id $clientId;

    #[ORM\Column(type: 'domain_id')]
    private readonly Id $userId;

    /** @var array<string> */
    #[ORM\Column(name: 'roles_json', type: 'json')]
    private array $roles;

    #[ORM\Column(length: 32)]
    private string $status;

    #[ORM\Column(type: 'domain_datetime')]
    private DateTime $createdAt;

    #[ORM\Column(type: 'domain_datetime')]
    private DateTime $updatedAt;

    /**
     * @param array<string> $roles
     */
    public function __construct(
        ClientMemberOutsideInterface $outside,
        Id $id,
        Id $clientId,
        Id $userId,
        array $roles,
    ) {
        $this->outside = $outside;
        $this->id = $id;
        $this->clientId = $clientId;
        $this->userId = $userId;
        $this->roles = $this->normalizeAndValidateRoles($roles);
        $this->status = self::STATUS_ACTIVE;
        $now = $this->outside()->now();
        $this->createdAt = $now;
        $this->updatedAt = $now;
        $this->outside()->record(new ClientMemberCreated(
            $this->id,
            $this->clientId,
            $this->userId,
            $this->roles,
            $this->createdAt,
        ));
    }

    /**
     * @param array<string> $roles
     */
    public function replaceRoles(array $roles): void
    {
        $newRoles = $this->normalizeAndValidateRoles($roles);
        if ($this->roles === $newRoles) {
            return;
        }

        $oldRoles = $this->roles;
        $this->roles = $newRoles;
        $this->updatedAt = $this->outside()->now();
        $this->outside()->record(new ClientMemberRolesChanged(
            $this->id,
            $oldRoles,
            $this->roles,
            $this->updatedAt,
        ));
    }

    /**
     * @param array<string> $roles
     */
    public function addRoles(array $roles): void
    {
        $mergedRoles = array_merge($this->roles, $roles);
        $newRoles = $this->normalizeAndValidateRoles($mergedRoles);
        if ($this->roles === $newRoles) {
            return;
        }

        $oldRoles = $this->roles;
        $this->roles = $newRoles;
        $this->updatedAt = $this->outside()->now();
        $this->outside()->record(new ClientMemberRolesChanged(
            $this->id,
            $oldRoles,
            $this->roles,
            $this->updatedAt,
        ));
    }

    /**
     * @param array<string> $roles
     */
    public function removeRoles(array $roles): void
    {
        $newRoles = array_diff($this->roles, $roles);
        $newRoles = $this->normalizeRoles($newRoles);
        if ($this->roles === $newRoles) {
            return;
        }

        $oldRoles = $this->roles;
        $this->roles = $newRoles;
        $this->updatedAt = $this->outside()->now();
        $this->outside()->record(new ClientMemberRolesChanged(
            $this->id,
            $oldRoles,
            $this->roles,
            $this->updatedAt,
        ));
    }

    public function suspend(): void
    {
        if (self::STATUS_SUSPENDED === $this->status) {
            return;
        }

        $this->status = self::STATUS_SUSPENDED;
        $this->updatedAt = $this->outside()->now();
        $this->outside()->record(new ClientMemberSuspended($this->id, $this->updatedAt));
    }

    public function unsuspend(): void
    {
        if (self::STATUS_ACTIVE === $this->status) {
            return;
        }

        $this->status = self::STATUS_ACTIVE;
        $this->updatedAt = $this->outside()->now();
        $this->outside()->record(new ClientMemberUnsuspended($this->id, $this->updatedAt));
    }

    private function outside(): ClientMemberOutsideInterface
    {
        if (null === $this->outside) {
            throw new \LogicException('Outside not attached. Entity was not properly hydrated.');
        }

        return $this->outside;
    }

    /**
     * @param array<string> $roles
     *
     * @return array<string>
     */
    private function normalizeAndValidateRoles(array $roles): array
    {
        $this->validateRoles($roles);

        return $this->normalizeRoles($roles);
    }

    /**
     * @param array<string> $roles
     */
    private function validateRoles(array $roles): void
    {
        foreach ($roles as $role) {
            if (!\in_array($role, self::ALLOWED_ROLES, true)) {
                throw new \InvalidArgumentException(\sprintf('Unknown role: %s', $role));
            }
        }
    }

    /**
     * @param array<string> $roles
     *
     * @return array<string>
     */
    private function normalizeRoles(array $roles): array
    {
        $roles = array_unique($roles);
        sort($roles);

        return $roles;
    }
}
