<?php

namespace App\Entity;

use App\Repository\DeviceChannelRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: DeviceChannelRepository::class)]
#[ORM\Table(name: 'device_channel')]
#[ORM\UniqueConstraint(name: 'device_channel_unique', columns: ['device_id', 'channel_index'])]
class DeviceChannel
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Device::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ?Device $device = null;

    #[ORM\Column(type: 'integer')]
    private int $channelIndex; // 1 or 2

    #[ORM\ManyToOne(targetEntity: AxleGroup::class)]
    #[ORM\JoinColumn(nullable: true)]
    private ?AxleGroup $axleGroup = null;

    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $labelOverride = null; // User custom name like "Trailer", "Drive", etc.

    #[ORM\Column(type: 'boolean')]
    private bool $enabled = true;

    // Calibration coefficients per channel
    #[ORM\Column(type: 'float', nullable: true)]
    private ?float $regressionIntercept = null;

    #[ORM\Column(type: 'float', nullable: true)]
    private ?float $regressionSlope = null;

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

    #[ORM\Column(type: 'string', length: 64, nullable: true)]
    private ?string $sensorType = null; // 'pressure', 'temperature', etc. for future expansion

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $displayOrder = null; // Custom display order for channels (including virtual steer as 0)

    #[ORM\OneToMany(mappedBy: 'deviceChannel', targetEntity: MicroDataChannel::class, orphanRemoval: true)]
    private Collection $microDataChannels;

    public function __construct()
    {
        $this->microDataChannels = new ArrayCollection();
    }

    // Getters and setters
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

    public function getChannelIndex(): int
    {
        return $this->channelIndex;
    }

    public function setChannelIndex(int $channelIndex): self
    {
        $this->channelIndex = $channelIndex;
        return $this;
    }

    public function getAxleGroup(): ?AxleGroup
    {
        return $this->axleGroup;
    }

    public function setAxleGroup(?AxleGroup $axleGroup): self
    {
        $this->axleGroup = $axleGroup;
        return $this;
    }

    public function getLabelOverride(): ?string
    {
        return $this->labelOverride;
    }

    public function setLabelOverride(?string $labelOverride): self
    {
        $this->labelOverride = $labelOverride;
        return $this;
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function setEnabled(bool $enabled): self
    {
        $this->enabled = $enabled;
        return $this;
    }

    public function getRegressionIntercept(): ?float
    {
        return $this->regressionIntercept;
    }

    public function setRegressionIntercept(?float $regressionIntercept): self
    {
        $this->regressionIntercept = $regressionIntercept;
        return $this;
    }

    public function getRegressionSlope(): ?float
    {
        return $this->regressionSlope;
    }

    public function setRegressionSlope(?float $regressionSlope): self
    {
        $this->regressionSlope = $regressionSlope;
        return $this;
    }

    public function getRegressionAirPressureCoeff(): ?float
    {
        return $this->regressionAirPressureCoeff;
    }

    public function setRegressionAirPressureCoeff(?float $regressionAirPressureCoeff): self
    {
        $this->regressionAirPressureCoeff = $regressionAirPressureCoeff;
        return $this;
    }

    public function getRegressionAmbientPressureCoeff(): ?float
    {
        return $this->regressionAmbientPressureCoeff;
    }

    public function setRegressionAmbientPressureCoeff(?float $regressionAmbientPressureCoeff): self
    {
        $this->regressionAmbientPressureCoeff = $regressionAmbientPressureCoeff;
        return $this;
    }

    public function getRegressionAirTempCoeff(): ?float
    {
        return $this->regressionAirTempCoeff;
    }

    public function setRegressionAirTempCoeff(?float $regressionAirTempCoeff): self
    {
        $this->regressionAirTempCoeff = $regressionAirTempCoeff;
        return $this;
    }

    public function getRegressionRsq(): ?float
    {
        return $this->regressionRsq;
    }

    public function setRegressionRsq(?float $regressionRsq): self
    {
        $this->regressionRsq = $regressionRsq;
        return $this;
    }

    public function getRegressionRmse(): ?float
    {
        return $this->regressionRmse;
    }

    public function setRegressionRmse(?float $regressionRmse): self
    {
        $this->regressionRmse = $regressionRmse;
        return $this;
    }

    public function getSensorType(): ?string
    {
        return $this->sensorType;
    }

    public function setSensorType(?string $sensorType): self
    {
        $this->sensorType = $sensorType;
        return $this;
    }

    public function getDisplayOrder(): ?int
    {
        return $this->displayOrder;
    }

    public function setDisplayOrder(?int $displayOrder): self
    {
        $this->displayOrder = $displayOrder;
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
            $microDataChannel->setDeviceChannel($this);
        }
        return $this;
    }

    public function removeMicroDataChannel(MicroDataChannel $microDataChannel): self
    {
        if ($this->microDataChannels->removeElement($microDataChannel)) {
            if ($microDataChannel->getDeviceChannel() === $this) {
                $microDataChannel->setDeviceChannel(null);
            }
        }
        return $this;
    }

    /**
     * Get display label - uses override if set, otherwise axle group label
     */
    public function getDisplayLabel(): string
    {
        if ($this->labelOverride) {
            return $this->labelOverride;
        }
        if ($this->axleGroup) {
            return $this->axleGroup->getLabel() ?? 'Channel ' . $this->channelIndex;
        }
        return 'Channel ' . $this->channelIndex;
    }

    public function __toString(): string
    {
        return $this->getDisplayLabel();
    }
}
