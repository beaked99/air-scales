<?php

namespace App\Entity;

use App\Repository\CalibrationRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: CalibrationRepository::class)]
class Calibration
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private ?Device $device = null;

    #[ORM\Column]
    private ?float $air_pressure = null;

    #[ORM\Column]
    private ?float $temperature = null;

    #[ORM\Column]
    private ?float $axle_weight = null;

    public function getId(): ?int
    {
        return $this->id;
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

    public function getAirPressure(): ?float
    {
        return $this->air_pressure;
    }

    public function setAirPressure(float $air_pressure): static
    {
        $this->air_pressure = $air_pressure;

        return $this;
    }

    public function getTemperature(): ?float
    {
        return $this->temperature;
    }

    public function setTemperature(float $temperature): static
    {
        $this->temperature = $temperature;

        return $this;
    }

    public function getAxleWeight(): ?float
    {
        return $this->axle_weight;
    }

    public function setAxleWeight(float $axle_weight): static
    {
        $this->axle_weight = $axle_weight;

        return $this;
    }
}
