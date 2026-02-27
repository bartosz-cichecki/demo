<?php

declare(strict_types=1);

namespace App\Client\Domain\Client;

use App\Client\Domain\Client\Event\ClientCreated;
use App\Client\Domain\Client\Event\ClientDeactivated;
use App\Client\Domain\Client\Event\ClientDescriptionChanged;
use App\Client\Domain\Client\Event\ClientRenamed;
use App\Client\Domain\Client\Outside\ClientOutsideInterface;
use App\SharedKernel\Domain\Attribute\OutsideField;
use App\SharedKernel\Domain\ValueObject\DateTime;
use App\SharedKernel\Domain\ValueObject\Id;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'clients', schema: 'client')]
final class Client
{
    private const string STATUS_ACTIVE = 'active';
    private const string STATUS_INACTIVE = 'inactive';

    #[OutsideField]
    private ?ClientOutsideInterface $outside = null;

    #[ORM\Id]
    #[ORM\Column(type: 'domain_id')]
    private readonly Id $id;

    #[ORM\Column(length: 120)]
    private string $name;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $description;

    #[ORM\Column(length: 20)]
    private string $status;

    #[ORM\Column(type: 'domain_datetime')]
    private DateTime $createdAt;

    #[ORM\Column(type: 'domain_datetime')]
    private DateTime $updatedAt;

    public function __construct(
        ClientOutsideInterface $outside,
        Id $id,
        string $name,
        ?string $description,
    ) {
        $this->outside = $outside;
        $this->id = $id;
        $this->name = $name;
        $this->name = trim($this->name);
        if ('' === $this->name) {
            throw new \InvalidArgumentException('Client name cannot be empty.');
        }
        if (mb_strlen($this->name) > 120) {
            throw new \InvalidArgumentException('Client name is too long.');
        }
        $this->description = null !== $description ? trim($description) : null;
        $this->status = self::STATUS_ACTIVE;
        $now = $this->outside()->now();
        $this->createdAt = $now;
        $this->updatedAt = $now;
        $this->outside()->record(new ClientCreated($this->id, $this->name, $this->description, $this->createdAt));
    }

    public function rename(string $newName): void
    {
        $this->assertActive();

        $newName = trim($newName);
        if ('' === $newName) {
            throw new \InvalidArgumentException('Client name cannot be empty.');
        }
        if (mb_strlen($newName) > 120) {
            throw new \InvalidArgumentException('Client name is too long.');
        }
        if ($this->name === $newName) {
            return;
        }

        $this->name = $newName;
        $this->updatedAt = $this->outside()->now();
        $this->outside()->record(new ClientRenamed($this->id, $this->name, $this->updatedAt));
    }

    public function changeDescription(?string $description): void
    {
        $this->assertActive();

        $newDescription = null !== $description ? trim($description) : null;
        if ($this->description === $newDescription) {
            return;
        }

        $this->description = $newDescription;
        $this->updatedAt = $this->outside()->now();
        $this->outside()->record(new ClientDescriptionChanged($this->id, $this->description, $this->updatedAt));
    }

    public function deactivate(): void
    {
        if (self::STATUS_INACTIVE === $this->status) {
            return;
        }
        $this->status = self::STATUS_INACTIVE;
        $this->updatedAt = $this->outside()->now();
        $this->outside()->record(new ClientDeactivated($this->id, $this->updatedAt));
    }

    private function outside(): ClientOutsideInterface
    {
        if (null === $this->outside) {
            throw new \LogicException('Outside not attached. Entity was not properly hydrated.');
        }

        return $this->outside;
    }

    private function assertActive(): void
    {
        if (self::STATUS_INACTIVE === $this->status) {
            throw new \DomainException('Inactive client cannot be changed.');
        }
    }
}
