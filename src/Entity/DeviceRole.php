<?php

namespace App\Entity;

use App\Entity\Traits\TimestampableTrait;
use Doctrine\ORM\Mapping as ORM;

#[ORM\HasLifecycleCallbacks]
#[ORM\Entity]
class DeviceRole
{
    use TimestampableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Device::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ?Device $device = null;

    #[ORM\ManyToOne(targetEntity: TruckConfiguration::class, inversedBy: 'deviceRoles')]
    #[ORM\JoinColumn(nullable: false)]
    private ?TruckConfiguration $truckConfiguration = null;

    #[ORM\Column(type: 'string', length: 50)]
    private ?string $role = null; // 'master', 'slave', 'trailer_front', 'trailer_rear', etc.

    #[ORM\Column(type: 'string', length: 100, nullable: true)]
    private ?string $position = null; // 'tractor', 'trailer_1_front', 'trailer_1_rear', etc.

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $sortOrder = null;

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $visualPosition = null; // x, y coordinates for dashboard display

    // Getters and setters
    public function getId(): ?int { return $this->id; }
    public function getDevice(): ?Device { return $this->device; }
    public function setDevice(?Device $device): self { $this->device = $device; return $this; }
    public function getTruckConfiguration(): ?TruckConfiguration { return $this->truckConfiguration; }
    public function setTruckConfiguration(?TruckConfiguration $truckConfiguration): self { $this->truckConfiguration = $truckConfiguration; return $this; }
    public function getRole(): ?string { return $this->role; }
    public function setRole(?string $role): self { $this->role = $role; return $this; }
    public function getPosition(): ?string { return $this->position; }
    public function setPosition(?string $position): self { $this->position = $position; return $this; }
    public function getSortOrder(): ?int { return $this->sortOrder; }
    public function setSortOrder(?int $sortOrder): self { $this->sortOrder = $sortOrder; return $this; }
    public function getVisualPosition(): ?array { return $this->visualPosition; }
    public function setVisualPosition(?array $visualPosition): self { $this->visualPosition = $visualPosition; return $this; }
}