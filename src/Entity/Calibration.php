<?php

namespace App\Entity;

use App\Repository\CalibrationRepository;
use Doctrine\ORM\Mapping as ORM;
use App\Entity\Traits\TimestampableTrait;

#[ORM\Entity(repositoryClass: CalibrationRepository::class)]
#[ORM\HasLifecycleCallbacks]
class Calibration
{
    use TimestampableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    // === Relationships ===

    #[ORM\ManyToOne(targetEntity: Device::class, inversedBy: 'calibrations')]
    #[ORM\JoinColumn(nullable: false)]
    private ?Device $device = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ?User $createdBy = null;

    // === Readings from device ===

    #[ORM\Column(type: 'float')]
    private float $airPressure;

    #[ORM\Column(type: 'float')]
    private float $ambientAirPressure;

    #[ORM\Column(type: 'float')]
    private float $airTemperature;

    #[ORM\Column(type: 'float')]
    private float $elevation;

    // === User input ===

    #[ORM\Column(type: 'float')]
    private float $scaleWeight;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $comment = null;

    // === Optional: Updated by (if you want extra tracking) ===

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true)]
    private ?User $updatedBy = null;

    // === Getters and Setters ===

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getDevice(): ?Device
    {
        return $this->device;
    }

    public function setDevice(?Device $device): self
    {
        $this->device = $device;
        return $this;
    }

    public function getCreatedBy(): ?User
    {
        return $this->createdBy;
    }

    public function setCreatedBy(?User $user): self
    {
        $this->createdBy = $user;
        return $this;
    }

    public function getUpdatedBy(): ?User
    {
        return $this->updatedBy;
    }

    public function setUpdatedBy(?User $user): self
    {
        $this->updatedBy = $user;
        return $this;
    }

    public function getAirPressure(): float
    {
        return $this->airPressure;
    }

    public function setAirPressure(float $airPressure): self
    {
        $this->airPressure = $airPressure;
        return $this;
    }

    public function getAmbientAirPressure(): float
    {
        return $this->ambientAirPressure;
    }

    public function setAmbientAirPressure(float $ambientAirPressure): self
    {
        $this->ambientAirPressure = $ambientAirPressure;
        return $this;
    }

    public function getAirTemperature(): float
    {
        return $this->airTemperature;
    }

    public function setAirTemperature(float $airTemperature): self
    {
        $this->airTemperature = $airTemperature;
        return $this;
    }

    public function getElevation(): float
    {
        return $this->elevation;
    }

    public function setElevation(float $elevation): self
    {
        $this->elevation = $elevation;
        return $this;
    }

    public function getScaleWeight(): float
    {
        return $this->scaleWeight;
    }

    public function setScaleWeight(float $scaleWeight): self
    {
        $this->scaleWeight = $scaleWeight;
        return $this;
    }
    public function getComment(): ?string
    {
        return $this->comment;
    }

    public function setComment(?string $comment): self
    {
        $this->comment = $comment;
        return $this;
    }
}
