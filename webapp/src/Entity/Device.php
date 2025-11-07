<?php

namespace App\Entity;

use App\Entity\Traits\TimestampableTrait;
use App\Repository\DeviceRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use App\Entity\Calibration;
use App\Entity\MicroData;
use App\Entity\User;
use App\Entity\Vehicle;

#[ORM\HasLifecycleCallbacks]
#[ORM\Entity(repositoryClass: DeviceRepository::class)]
class Device
{
    use TimestampableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Vehicle::class, inversedBy: 'devices')]
    #[ORM\JoinColumn(nullable: true)]
    private ?Vehicle $vehicle = null;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $serialNumber = null;

    #[ORM\Column(length: 17, nullable: true)]
    private ?string $macAddress = null;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $deviceType = null;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $firmwareVersion = null;

    #[ORM\ManyToOne(targetEntity: User::class, inversedBy: 'devices')]
    #[ORM\JoinColumn(name: "sold_to_id", referencedColumnName: "id", nullable: true)]
    private ?User $soldTo = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $orderDate = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $shipDate = null;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $trackingId = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $notes = null;

    #[ORM\OneToMany(mappedBy: 'device', targetEntity: Calibration::class)]
    private Collection $calibrations;

    #[ORM\OneToMany(mappedBy: 'device', targetEntity: MicroData::class, orphanRemoval: true)]
    private Collection $microData;

    #[ORM\Column(type: 'float', nullable: true)]
    private ?float $regressionIntercept = null;

    #[ORM\Column(type: 'float', nullable: true)]
    private ?float $regressionAirPressureCoeff = null;

    #[ORM\Column(type: 'float', nullable: true)]
    private ?float $regressionAmbientPressureCoeff = null;

    #[ORM\Column(type: 'float', nullable: true)]
    private ?float $regressionAirTempCoeff = null;

    #[ORM\Column(type: 'float', nullable: true)]
    private ?float $regressionRsq = null;

    #[ORM\Column(type: 'float', nullable: true)]
    private ?float $regressionRmse = null;

    #[ORM\OneToMany(mappedBy: 'device', targetEntity: DeviceAccess::class)]
    private Collection $deviceAccesses;

    // Mesh networking fields
    #[ORM\Column(type: 'string', length: 20, nullable: true)]
    private ?string $currentRole = null; // 'master', 'slave', 'discovery'

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $meshConfiguration = null; // Store mesh topology and role assignments

    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?\DateTimeInterface $lastMeshActivity = null;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $signalStrength = null; // RSSI for mesh connectivity

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $connectedSlaves = null; // MAC addresses of connected slave devices

    #[ORM\Column(type: 'string', length: 17, nullable: true)]
    private ?string $masterDeviceMac = null; // MAC of master device if this is a slave

    public function __construct()
    {
        $this->calibrations = new ArrayCollection();
        $this->microData = new ArrayCollection();
        $this->deviceAccesses = new ArrayCollection();
    }

    // Basic getters/setters
    public function getId(): ?int { return $this->id; }
    public function getVehicle(): ?Vehicle { return $this->vehicle; }
    public function setVehicle(?Vehicle $vehicle): static { $this->vehicle = $vehicle; return $this; }
    public function getSerialNumber(): ?string { return $this->serialNumber; }
    public function setSerialNumber(?string $serialNumber): static { $this->serialNumber = $serialNumber; return $this; }
    public function getMacAddress(): ?string { return $this->macAddress; }
    public function setMacAddress(?string $macAddress): static { $this->macAddress = $macAddress; return $this; }
    public function getDeviceType(): ?string { return $this->deviceType; }
    public function setDeviceType(?string $deviceType): static { $this->deviceType = $deviceType; return $this; }
    public function getFirmwareVersion(): ?string { return $this->firmwareVersion; }
    public function setFirmwareVersion(?string $firmwareVersion): static { $this->firmwareVersion = $firmwareVersion; return $this; }
    public function getSoldTo(): ?User { return $this->soldTo; }
    public function setSoldTo(?User $soldTo): static { $this->soldTo = $soldTo; return $this; }
    public function getOrderDate(): ?\DateTimeImmutable { return $this->orderDate; }
    public function setOrderDate(?\DateTimeImmutable $orderDate): static { $this->orderDate = $orderDate; return $this; }
    public function getShipDate(): ?\DateTimeImmutable { return $this->shipDate; }
    public function setShipDate(?\DateTimeImmutable $shipDate): static { $this->shipDate = $shipDate; return $this; }
    public function getTrackingId(): ?string { return $this->trackingId; }
    public function setTrackingId(?string $trackingId): static { $this->trackingId = $trackingId; return $this; }
    public function getNotes(): ?string { return $this->notes; }
    public function setNotes(?string $notes): static { $this->notes = $notes; return $this; }

    // Collections
    public function getCalibrations(): Collection { return $this->calibrations; }
    public function getMicroData(): Collection { return $this->microData; }
    public function getDeviceAccesses(): Collection { return $this->deviceAccesses; }

    public function addCalibration(Calibration $calibration): static
    {
        if (!$this->calibrations->contains($calibration)) {
            $this->calibrations->add($calibration);
            $calibration->setDevice($this);
        }
        return $this;
    }

    public function removeCalibration(Calibration $calibration): static
    {
        if ($this->calibrations->removeElement($calibration)) {
            if ($calibration->getDevice() === $this) {
                $calibration->setDevice(null);
            }
        }
        return $this;
    }

    public function addMicroDatum(MicroData $datum): self
    {
        if (!$this->microData->contains($datum)) {
            $this->microData[] = $datum;
            $datum->setDevice($this);
        }
        return $this;
    }

    public function removeMicroDatum(MicroData $datum): self
    {
        if ($this->microData->removeElement($datum)) {
            if ($datum->getDevice() === $this) {
                $datum->setDevice(null);
            }
        }
        return $this;
    }

    // Regression coefficients
    public function getRegressionIntercept(): ?float { return $this->regressionIntercept; }
    public function setRegressionIntercept(?float $regressionIntercept): self { $this->regressionIntercept = $regressionIntercept; return $this; }
    public function getRegressionAirPressureCoeff(): ?float { return $this->regressionAirPressureCoeff; }
    public function setRegressionAirPressureCoeff(?float $value): self { $this->regressionAirPressureCoeff = $value; return $this; }
    public function getRegressionAmbientPressureCoeff(): ?float { return $this->regressionAmbientPressureCoeff; }
    public function setRegressionAmbientPressureCoeff(?float $value): self { $this->regressionAmbientPressureCoeff = $value; return $this; }
    public function getRegressionAirTempCoeff(): ?float { return $this->regressionAirTempCoeff; }
    public function setRegressionAirTempCoeff(?float $value): self { $this->regressionAirTempCoeff = $value; return $this; }
    public function getRegressionRsq(): ?float { return $this->regressionRsq; }
    public function setRegressionRsq(?float $rsq): self { $this->regressionRsq = $rsq; return $this; }
    public function getRegressionRmse(): ?float { return $this->regressionRmse; }
    public function setRegressionRmse(?float $rmse): self { $this->regressionRmse = $rmse; return $this; }

    // Mesh networking getters/setters
    public function getCurrentRole(): ?string { return $this->currentRole; }
    public function setCurrentRole(?string $currentRole): self { $this->currentRole = $currentRole; return $this; }
    public function getMeshConfiguration(): ?array { return $this->meshConfiguration; }
    public function setMeshConfiguration(?array $meshConfiguration): self { $this->meshConfiguration = $meshConfiguration; return $this; }
    public function getLastMeshActivity(): ?\DateTimeInterface { return $this->lastMeshActivity; }
    public function setLastMeshActivity(?\DateTimeInterface $lastMeshActivity): self { $this->lastMeshActivity = $lastMeshActivity; return $this; }
    public function getSignalStrength(): ?int { return $this->signalStrength; }
    public function setSignalStrength(?int $signalStrength): self { $this->signalStrength = $signalStrength; return $this; }
    public function getConnectedSlaves(): ?array { return $this->connectedSlaves; }
    public function setConnectedSlaves(?array $connectedSlaves): self { $this->connectedSlaves = $connectedSlaves; return $this; }
    public function getMasterDeviceMac(): ?string { return $this->masterDeviceMac; }
    public function setMasterDeviceMac(?string $masterDeviceMac): self { $this->masterDeviceMac = $masterDeviceMac; return $this; }

    // Mesh helper methods
    public function isMeshMaster(): bool { return $this->currentRole === 'master'; }
    public function isMeshSlave(): bool { return $this->currentRole === 'slave'; }
    public function isInMeshNetwork(): bool { return in_array($this->currentRole, ['master', 'slave']); }

    // Display methods
    public function __toString(): string
    {
        if ($this->vehicle) {
            return $this->vehicle->__toString() . ' - ' . ($this->getSerialNumber() ?? 'Device #' . $this->getId());
        }
        return $this->getSerialNumber() ?? 'Device #' . $this->getId();
    }

    public function getVehicleDisplay(): string
    {
        return $this->vehicle ? $this->vehicle->__toString() : 'No Vehicle Assigned';
    }

    /**
     * Transient property to hold latest micro data
     */
    public ?MicroData $latestMicroData = null;

    /**
     * Transient property to hold connection status
     */
    public ?string $connectionStatus = null;

    /**
     * Transient property to hold last seen text
     */
    public ?string $lastSeenText = null;

    // Add these getter/setter methods at the bottom of your Device class

    public function getLatestMicroData(): ?MicroData 
    { 
        return $this->latestMicroData; 
    }

    public function setLatestMicroData(?MicroData $latestMicroData): self 
    { 
        $this->latestMicroData = $latestMicroData; 
        return $this; 
    }

    public function getConnectionStatus(): ?string 
    { 
        return $this->connectionStatus; 
    }

    public function setConnectionStatus(?string $connectionStatus): self 
    { 
        $this->connectionStatus = $connectionStatus; 
        return $this; 
    }

    public function getLastSeenText(): ?string 
    { 
        return $this->lastSeenText; 
    }

    public function setLastSeenText(?string $lastSeenText): self 
    { 
        $this->lastSeenText = $lastSeenText; 
        return $this; 
    }
}