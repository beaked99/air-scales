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
    private Collection $deviceRoles;

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $layout = null; // Store visual layout information

    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?\DateTimeInterface $lastUsed = null;

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