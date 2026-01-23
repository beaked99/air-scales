<?php

namespace App\Entity;

use App\Entity\Traits\TimestampableTrait;
use Doctrine\ORM\Mapping as ORM;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;

#[ORM\HasLifecycleCallbacks]
#[ORM\Entity]
class TruckConfiguration
{
    use TimestampableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(type: 'string', length: 255)]
    private ?string $name = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ?User $owner = null;

    #[ORM\OneToMany(mappedBy: 'truckConfiguration', targetEntity: DeviceRole::class, cascade: ['persist', 'remove'])]
    #[ORM\OrderBy(['sortOrder' => 'ASC', 'id' => 'ASC'])]
    private Collection $deviceRoles;

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $layout = null; // Store visual layout information

    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?\DateTimeInterface $lastUsed = null;

    #[ORM\Column(type: 'boolean')]
    private bool $isActive = false;

    #[ORM\Column(type: 'boolean')]
    private bool $isShared = false;

    #[ORM\Column(type: 'integer', options: ['default' => 0])]
    private int $sortOrder = 0;

    public function __construct()
    {
        $this->deviceRoles = new ArrayCollection();
    }

    // Getters and setters
    public function getId(): ?int { return $this->id; }
    public function getName(): ?string { return $this->name; }
    public function setName(string $name): self { $this->name = $name; return $this; }
    public function getOwner(): ?User { return $this->owner; }
    public function setOwner(?User $owner): self { $this->owner = $owner; return $this; }
    public function getDeviceRoles(): Collection { return $this->deviceRoles; }
    public function getLayout(): ?array { return $this->layout; }
    public function setLayout(?array $layout): self { $this->layout = $layout; return $this; }
    public function getLastUsed(): ?\DateTimeInterface { return $this->lastUsed; }
    public function setLastUsed(?\DateTimeInterface $lastUsed): self { $this->lastUsed = $lastUsed; return $this; }
    public function isActive(): bool { return $this->isActive; }
    public function setIsActive(bool $isActive): self { $this->isActive = $isActive; return $this; }
    public function isShared(): bool { return $this->isShared; }
    public function setIsShared(bool $isShared): self { $this->isShared = $isShared; return $this; }
    public function getSortOrder(): int { return $this->sortOrder; }
    public function setSortOrder(int $sortOrder): self { $this->sortOrder = $sortOrder; return $this; }

    /**
     * Get all axle groups from all device roles in this configuration
     */
    public function getAxleGroups(): array
    {
        $axleGroups = [];
        foreach ($this->deviceRoles as $deviceRole) {
            $device = $deviceRole->getDevice();
            if ($device) {
                foreach ($device->getDeviceChannels() as $channel) {
                    $axleGroup = $channel->getAxleGroup();
                    if ($axleGroup && !in_array($axleGroup, $axleGroups, true)) {
                        $axleGroups[] = $axleGroup;
                    }
                }
            }
        }
        return $axleGroups;
    }

    /**
     * Get total weight from all devices in this configuration
     */
    public function getTotalWeight(): float
    {
        $total = 0.0;
        foreach ($this->deviceRoles as $deviceRole) {
            $device = $deviceRole->getDevice();
            if ($device) {
                foreach ($device->getDeviceChannels() as $channel) {
                    // This would need live data - placeholder for now
                    $total += 0; // TODO: Get latest weight from channel
                }
            }
        }
        return $total;
    }

    /**
     * Check if any axle group in this configuration needs calibration
     */
    public function needsCalibration(): bool
    {
        foreach ($this->getAxleGroups() as $axleGroup) {
            // Check via DeviceChannel calibration status
            // Simplified check - would need proper implementation
        }
        return false; // TODO: Implement proper check
    }

    public function addDeviceRole(DeviceRole $deviceRole): self
    {
        if (!$this->deviceRoles->contains($deviceRole)) {
            $this->deviceRoles[] = $deviceRole;
            $deviceRole->setTruckConfiguration($this);
        }
        return $this;
    }

    public function removeDeviceRole(DeviceRole $deviceRole): self
    {
        if ($this->deviceRoles->removeElement($deviceRole)) {
            if ($deviceRole->getTruckConfiguration() === $this) {
                $deviceRole->setTruckConfiguration(null);
            }
        }
        return $this;
    }
}