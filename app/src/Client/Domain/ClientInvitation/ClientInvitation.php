<?php

declare(strict_types=1);

namespace App\Client\Domain\ClientInvitation;

use App\Client\Domain\ClientInvitation\Event\ClientInvitationAccepted;
use App\Client\Domain\ClientInvitation\Event\ClientInvitationCreated;
use App\Client\Domain\ClientInvitation\Event\ClientInvitationRejected;
use App\Client\Domain\ClientInvitation\Event\ClientInvitationRevoked;
use App\Client\Domain\ClientInvitation\Exception\ClientInvitationNotAddressedToUserException;
use App\Client\Domain\ClientInvitation\Exception\ClientInvitationNotPendingException;
use App\Client\Domain\ClientInvitation\Exception\ClientInvitationRoleNotAllowedException;
use App\Client\Domain\ClientInvitation\Outside\ClientInvitationOutsideInterface;
use App\Client\Domain\ClientMember\ClientMember;
use App\Client\Domain\ClientMember\Factory\ClientMemberFactoryInterface;
use App\Client\Domain\ClientMember\Repository\Exception\ClientMemberAlreadyExistsException;
use App\SharedKernel\Domain\Attribute\OutsideField;
use App\SharedKernel\Domain\ValueObject\DateTime;
use App\SharedKernel\Domain\ValueObject\Email;
use App\SharedKernel\Domain\ValueObject\Id;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'client_invitations', schema: 'client')]
final class ClientInvitation
{
    public const string STATUS_PENDING = 'pending';
    public const string STATUS_ACCEPTED = 'accepted';
    public const string STATUS_REJECTED = 'rejected';
    public const string STATUS_REVOKED = 'revoked';

    #[OutsideField]
    private ?ClientInvitationOutsideInterface $outside = null;

    #[ORM\Id]
    #[ORM\Column(type: 'domain_id')]
    private readonly Id $id;

    #[ORM\Column(type: 'domain_id')]
    private readonly Id $clientId;

    #[ORM\Column(type: 'domain_email', length: 255)]
    private readonly Email $email;

    #[ORM\Column(length: 32)]
    private readonly string $role;

    #[ORM\Column(length: 32)]
    private string $status;

    #[ORM\Column(type: 'domain_datetime')]
    private DateTime $createdAt;

    #[ORM\Column(type: 'domain_datetime')]
    private DateTime $updatedAt;

    public function __construct(
        ClientInvitationOutsideInterface $outside,
        Id $id,
        Id $clientId,
        Email $email,
        string $role,
    ) {
        if (!\in_array($role, ClientMember::ALLOWED_ROLES, true)) {
            throw new \InvalidArgumentException(\sprintf('Unknown role: %s', $role));
        }

        $this->outside = $outside;
        $this->id = $id;
        $this->clientId = $clientId;
        $this->email = $email;
        $this->role = $role;
        $this->status = self::STATUS_PENDING;
        $now = $this->outside()->now();
        $this->createdAt = $now;
        $this->updatedAt = $now;
        $this->outside()->record(new ClientInvitationCreated(
            $this->id,
            $this->clientId,
            (string) $this->email,
            $this->role,
            $this->createdAt,
        ));
    }

    /**
     * Accepting creates the membership in the same unit of work as the status change.
     *
     * @throws ClientInvitationNotAddressedToUserException
     * @throws ClientInvitationNotPendingException
     * @throws ClientMemberAlreadyExistsException
     */
    public function accept(Id $userId, Id $clientMemberId, ClientMemberFactoryInterface $clientMemberFactory): ClientMember
    {
        $this->assertAddressedTo($userId);
        $this->assertPending();

        $member = $clientMemberFactory->create($clientMemberId, $this->clientId, $userId, [$this->role]);

        $this->changeStatus(self::STATUS_ACCEPTED);
        $this->outside()->record(new ClientInvitationAccepted(
            $this->id,
            $this->clientId,
            $userId,
            $this->role,
            $this->updatedAt,
        ));

        return $member;
    }

    /**
     * @throws ClientInvitationNotAddressedToUserException
     * @throws ClientInvitationNotPendingException
     */
    public function reject(Id $userId): void
    {
        $this->assertAddressedTo($userId);
        $this->assertPending();

        $this->changeStatus(self::STATUS_REJECTED);
        $this->outside()->record(new ClientInvitationRejected($this->id, $userId, $this->updatedAt));
    }

    /**
     * Client admins manage only invitations with role user; role admin belongs to the platform.
     *
     * @throws ClientInvitationRoleNotAllowedException
     * @throws ClientInvitationNotPendingException
     */
    public function revokeByClientAdmin(): void
    {
        if (ClientMember::ROLE_USER !== $this->role) {
            throw new ClientInvitationRoleNotAllowedException($this->role);
        }
        $this->revoke();
    }

    public function revokeByPlatformAdmin(): void
    {
        if (ClientMember::ROLE_ADMIN !== $this->role) {
            throw new ClientInvitationRoleNotAllowedException($this->role);
        }
        $this->revoke();
    }

    private function revoke(): void
    {
        $this->assertPending();

        $this->changeStatus(self::STATUS_REVOKED);
        $this->outside()->record(new ClientInvitationRevoked($this->id, $this->updatedAt));
    }

    private function assertAddressedTo(Id $userId): void
    {
        $userEmail = $this->outside()->userEmail($userId);
        if (null === $userEmail || !$userEmail->equals($this->email)) {
            throw new ClientInvitationNotAddressedToUserException($this->id);
        }
    }

    private function assertPending(): void
    {
        if (self::STATUS_PENDING !== $this->status) {
            throw new ClientInvitationNotPendingException($this->id);
        }
    }

    private function changeStatus(string $status): void
    {
        $this->status = $status;
        $this->updatedAt = $this->outside()->now();
    }

    private function outside(): ClientInvitationOutsideInterface
    {
        if (null === $this->outside) {
            throw new \LogicException('Outside not attached. Entity was not properly hydrated.');
        }

        return $this->outside;
    }
}
