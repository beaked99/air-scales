<?php

//Entity/MicroData.php

namespace App\Entity;

use App\Repository\MicroDataRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: MicroDataRepository::class)]
class MicroData
{
    public function __construct()
    {
        $this->microDataChannels = new ArrayCollection();
    }
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Device::class, inversedBy: 'microData')]
    #[ORM\JoinColumn(nullable: false)]
    private ?Device $device = null;

    #[ORM\Column(type: 'float', nullable: true)]
    private ?float $atmosphericPressure = null;

    #[ORM\Column(type: 'float', nullable: true)]
    private ?float $temperature = null;

    #[ORM\Column(type: 'float', nullable: true)]
    private ?float $elevation = null;

    // GPS from phone - nullable for enrichment
    #[ORM\Column(type: 'float', nullable: true)]
    private ?float $gpsLat = null;

    #[ORM\Column(type: 'float', nullable: true)]
    private ?float $gpsLng = null;

    #[ORM\Column(type: 'float', nullable: true)]
    private ?float $gpsAccuracyM = null; // GPS accuracy in meters

    // ESP-NOW signal strength (dBm) - negative values, e.g., -52 dBm
    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $espnowRssi = null;

    #[ORM\Column(type: 'string', length: 255)]
    private string $macAddress;

    #[ORM\Column(type: 'datetime')]
    private \DateTimeInterface $timestamp;

    // Per-channel readings via relationship
    #[ORM\OneToMany(mappedBy: 'microData', targetEntity: MicroDataChannel::class, orphanRemoval: true, cascade: ['persist', 'remove'])]
    private Collection $microDataChannels;

    // DEPRECATED: Old single-channel fields - keep for backward compatibility during migration
    #[ORM\Column(type: 'float', nullable: true)]
    private ?float $mainAirPressure = null; // DEPRECATED: Use microDataChannels instead

    #[ORM\Column(type: 'float', nullable: true)]
    private ?float $weight = null; // DEPRECATED: Use microDataChannels instead

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

    public function getMainAirPressure(): ?float
    {
        return $this->mainAirPressure;
    }

    public function setMainAirPressure(?float $mainAirPressure): self
    {
        $this->mainAirPressure = $mainAirPressure;
        return $this;
    }

    public function getAtmosphericPressure(): ?float
    {
        return $this->atmosphericPressure;
    }

    public function setAtmosphericPressure(?float $atmosphericPressure): self
    {
        $this->atmosphericPressure = $atmosphericPressure;
        return $this;
    }

    public function getTemperature(): ?float
    {
        return $this->temperature;
    }

    public function setTemperature(?float $temperature): self
    {
        $this->temperature = $temperature;
        return $this;
    }

    public function getElevation(): ?float
    {
        return $this->elevation;
    }

    public function setElevation(?float $elevation): self
    {
        $this->elevation = $elevation;
        return $this;
    }

    public function getGpsLat(): ?float
    {
        return $this->gpsLat;
    }

    public function setGpsLat(?float $gpsLat): self
    {
        $this->gpsLat = $gpsLat;
        return $this;
    }

    public function getGpsLng(): ?float
    {
        return $this->gpsLng;
    }

    public function setGpsLng(?float $gpsLng): self
    {
        $this->gpsLng = $gpsLng;
        return $this;
    }

    public function getGpsAccuracyM(): ?float
    {
        return $this->gpsAccuracyM;
    }

    public function setGpsAccuracyM(?float $gpsAccuracyM): self
    {
        $this->gpsAccuracyM = $gpsAccuracyM;
        return $this;
    }

    public function getEspnowRssi(): ?int
    {
        return $this->espnowRssi;
    }

    public function setEspnowRssi(?int $espnowRssi): self
    {
        $this->espnowRssi = $espnowRssi;
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

    public function getWeight(): ?float
    {
        return $this->weight;
    }

    public function setWeight(?float $weight): self
    {
        $this->weight = $weight;
        return $this;
    }

    /**
     * @return Collection<int, MicroDataChannel>
     */
    public function getMicroDataChannels(): Collection
    {
        return $this->microDataChannels;
    }

    public function addMicroDataChannel(MicroDataChannel $microDataChannel): self
    {
        if (!$this->microDataChannels->contains($microDataChannel)) {
            $this->microDataChannels->add($microDataChannel);
            $microDataChannel->setMicroData($this);
        }
        return $this;
    }

    public function removeMicroDataChannel(MicroDataChannel $microDataChannel): self
    {
        if ($this->microDataChannels->removeElement($microDataChannel)) {
            if ($microDataChannel->getMicroData() === $this) {
                $microDataChannel->setMicroData(null);
            }
        }
        return $this;
    }
}