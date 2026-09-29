<?php

declare(strict_types=1);

namespace App\Tests\Behat\Support\Fixture;

use App\SharedKernel\Domain\ValueObject\Id;

final class FixtureRegistry
{
    /** @var array<string, ClientFixture> */
    private array $clients = [];

    /** @var array<string, UserFixture> */
    private array $users = [];

    /** @var array<string, Id> */
    private array $invitations = [];

    private ?string $authenticatedUserId = null;
    private ?string $authenticatedClientId = null;
    private bool $authenticatedIsPlatformAdmin = false;

    public function putClient(string $alias, ClientFixture $fixture): void
    {
        $this->clients[$alias] = $fixture;
    }

    public function getClient(string $alias): ClientFixture
    {
        if (!isset($this->clients[$alias])) {
            throw new \RuntimeException(\sprintf('Client fixture "%s" not found. Available: %s', $alias, implode(', ', array_keys($this->clients)) ?: '(none)'));
        }

        return $this->clients[$alias];
    }

    public function putUser(string $alias, UserFixture $fixture): void
    {
        $this->users[$alias] = $fixture;
    }

    public function getUser(string $alias): UserFixture
    {
        if (!isset($this->users[$alias])) {
            throw new \RuntimeException(\sprintf('User fixture "%s" not found. Available: %s', $alias, implode(', ', array_keys($this->users)) ?: '(none)'));
        }

        return $this->users[$alias];
    }

    public function putInvitation(string $email, string $clientAlias, Id $id): void
    {
        $this->invitations[$this->invitationKey($email, $clientAlias)] = $id;
    }

    public function getInvitation(string $email, string $clientAlias): Id
    {
        $key = $this->invitationKey($email, $clientAlias);
        if (!isset($this->invitations[$key])) {
            throw new \RuntimeException(\sprintf('Invitation fixture "%s" not found. Available: %s', $key, implode(', ', array_keys($this->invitations)) ?: '(none)'));
        }

        return $this->invitations[$key];
    }

    public function clear(): void
    {
        $this->clients = [];
        $this->users = [];
        $this->invitations = [];
        $this->authenticatedUserId = null;
        $this->authenticatedClientId = null;
        $this->authenticatedIsPlatformAdmin = false;
    }

    public function setAuthenticatedSession(string $userId, ?string $activeClientId, bool $isPlatformAdmin = false): void
    {
        $this->authenticatedUserId = $userId;
        $this->authenticatedClientId = $activeClientId;
        $this->authenticatedIsPlatformAdmin = $isPlatformAdmin;
    }

    /**
     * @return array{userId: string, activeClientId: ?string, isPlatformAdmin: bool}|null
     */
    public function getAuthenticatedSession(): ?array
    {
        if (null === $this->authenticatedUserId) {
            return null;
        }

        return [
            'userId' => $this->authenticatedUserId,
            'activeClientId' => $this->authenticatedClientId,
            'isPlatformAdmin' => $this->authenticatedIsPlatformAdmin,
        ];
    }

    private function invitationKey(string $email, string $clientAlias): string
    {
        return mb_strtolower($email) . ' -> ' . $clientAlias;
    }
}
