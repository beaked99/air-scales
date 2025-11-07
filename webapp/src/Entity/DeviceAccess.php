<?php

namespace App\Entity;

use App\Repository\DeviceAccessRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: DeviceAccessRepository::class)]
#[ORM\Table(name: 'device_access')]
#[ORM\UniqueConstraint(name: 'user_device_unique', columns: ['user_id', 'device_id'])]
class DeviceAccess
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ?User $user = null;

    #[ORM\ManyToOne(targetEntity: Device::class, inversedBy: 'deviceAccesses')]
    #[ORM\JoinColumn(nullable: false)]
    private ?Device $device = null;

    #[ORM\Column]
    private \DateTimeImmutable $firstSeenAt;

    #[ORM\Column]
    private \DateTime $lastConnectedAt;
    
    #[ORM\Column(type: 'boolean')]
    private bool $isActive = true;

    public function __construct()
    {
        $this->firstSeenAt = new \DateTimeImmutable();
        $this->lastConnectedAt = new \DateTime();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): ?User
    {
        return $this->user;
    }

    public function setUser(?User $user): static
    {
        $this->user = $user;
        return $this;
    }

    public function getDevice(): ?Device
    {
        return $this->device;
    }

    public function setDevice(?Device $device): static
    {
        $this->device = $device;
        return $this;
    }

    public function getFirstSeenAt(): ?\DateTimeImmutable
    {
        return $this->firstSeenAt;
    }

    public function setFirstSeenAt(?\DateTimeImmutable $firstSeenAt): static
    {
        $this->firstSeenAt = $firstSeenAt;
        return $this;
    }

    public function getLastConnectedAt(): ?\DateTimeInterface
    {
        return $this->lastConnectedAt;
    }

    public function setLastConnectedAt(?\DateTimeInterface $lastConnectedAt): static
    {
        $this->lastConnectedAt = $lastConnectedAt;
        return $this;
    }

    public function isActive(): bool
    {
        return $this->isActive;
    }

    public function setIsActive(bool $isActive): self
    {
        $this->isActive = $isActive;
        return $this;
    }

    // Alias method for backwards compatibility with new controller
    public function getLastAccessedAt(): ?\DateTimeInterface
    {
        return $this->lastConnectedAt;
    }

    public function setLastAccessedAt(?\DateTimeInterface $lastAccessedAt): static
    {
        $this->lastConnectedAt = $lastAccessedAt;
        return $this;
    }

    // Alias method for backwards compatibility with new controller  
    public function getCreatedAt(): ?\DateTimeImmutable
    {
        return $this->firstSeenAt;
    }

    public function setCreatedAt(?\DateTimeImmutable $createdAt): static
    {
        $this->firstSeenAt = $createdAt;
        return $this;
    }

    public function __toString(): string
    {
        return sprintf("Device #%d <-> User %s", $this->device?->getId(), $this->user?->getEmail());
    }
}