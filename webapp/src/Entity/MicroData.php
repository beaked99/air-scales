<?php

//Entity/MicroData.php

namespace App\Entity;

use App\Repository\MicroDataRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: MicroDataRepository::class)]
class MicroData
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Device::class, inversedBy: 'microData')]
    #[ORM\JoinColumn(nullable: false)]
    private ?Device $device = null;

    #[ORM\Column(type: 'float')]
    private float $mainAirPressure;

    #[ORM\Column(type: 'float')]
    private float $atmosphericPressure;

    #[ORM\Column(type: 'float')]
    private float $temperature;

    #[ORM\Column(type: 'float')]
    private float $elevation;

    #[ORM\Column(type: 'float')]
    private float $gpsLat;

    #[ORM\Column(type: 'float')]
    private float $gpsLng;

    #[ORM\Column(type: 'string', length: 255)]
    private string $macAddress;

    #[ORM\Column(type: 'datetime')]
    private \DateTimeInterface $timestamp;

    #[ORM\Column(type: 'float')]
    private float $weight;

    // GETTERS AND SETTERS

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

    public function getMainAirPressure(): float
    {
        return $this->mainAirPressure;
    }

    public function setMainAirPressure(float $mainAirPressure): self
    {
        $this->mainAirPressure = $mainAirPressure;
        return $this;
    }

    public function getAtmosphericPressure(): float
    {
        return $this->atmosphericPressure;
    }

    public function setAtmosphericPressure(float $atmosphericPressure): self
    {
        $this->atmosphericPressure = $atmosphericPressure;
        return $this;
    }

    public function getTemperature(): float
    {
        return $this->temperature;
    }

    public function setTemperature(float $temperature): self
    {
        $this->temperature = $temperature;
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

    public function getGpsLat(): float
    {
        return $this->gpsLat;
    }

    public function setGpsLat(float $gpsLat): self
    {
        $this->gpsLat = $gpsLat;
        return $this;
    }

    public function getGpsLng(): float
    {
        return $this->gpsLng;
    }

    public function setGpsLng(float $gpsLng): self
    {
        $this->gpsLng = $gpsLng;
        return $this;
    }

    public function getMacAddress(): string
    {
        return $this->macAddress;
    }

    public function setMacAddress(string $macAddress): self
    {
        $this->macAddress = $macAddress;
        return $this;
    }

    public function getTimestamp(): \DateTimeInterface
    {
        return $this->timestamp;
    }

    public function setTimestamp(\DateTimeInterface $timestamp): self
    {
        $this->timestamp = $timestamp;
        return $this;
    }

    public function getWeight(): float
    {
        return $this->weight;
    }

    public function setWeight(float $weight): self
    {
        $this->weight = $weight;
        return $this;
    }
}