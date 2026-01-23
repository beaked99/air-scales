<?php

namespace App\Entity;

use App\Repository\AxleGroupRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: AxleGroupRepository::class)]
class AxleGroup
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private ?string $name = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $label= null;

    #[ORM\OneToMany(mappedBy: 'axleGroup', targetEntity: DeviceChannel::class)]
    private Collection $deviceChannels;

    public function __construct()
    {
        $this->deviceChannels = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;

        return $this;
    }

    public function getLabel(): ?string
    {
        return $this->label;
    }

    public function setLabel(?string $label): static
    {
        $this->label = $label;

        return $this;
    }

    /**
     * @return Collection<int, DeviceChannel>
     */
    public function getDeviceChannels(): Collection
    {
        return $this->deviceChannels;
    }

    public function addDeviceChannel(DeviceChannel $deviceChannel): self
    {
        if (!$this->deviceChannels->contains($deviceChannel)) {
            $this->deviceChannels->add($deviceChannel);
            $deviceChannel->setAxleGroup($this);
        }
        return $this;
    }

    public function removeDeviceChannel(DeviceChannel $deviceChannel): self
    {
        if ($this->deviceChannels->removeElement($deviceChannel)) {
            if ($deviceChannel->getAxleGroup() === $this) {
                $deviceChannel->setAxleGroup(null);
            }
        }
        return $this;
    }

    /**
     * Get calibration status for this axle group
     * Returns: 'good' (5+ points), 'warning' (1-4 points), 'critical' (0 points)
     */
    public function getCalibrationStatus(): string
    {
        $minPoints = PHP_INT_MAX;

        foreach ($this->deviceChannels as $channel) {
            $count = 0;
            // Count calibrations for this channel
            // This is a simplified version - you'd query the Calibration repository in practice
            foreach ($channel->getDevice()->getCalibrations() as $calibration) {
                if ($calibration->getDeviceChannel() === $channel) {
                    $count++;
                }
            }
            $minPoints = min($minPoints, $count);
        }

        if ($minPoints === PHP_INT_MAX || $minPoints === 0) {
            return 'critical';
        }

        return $minPoints >= 5 ? 'good' : 'warning';
    }

    /**
     * Get the minimum number of calibration points across all channels
     */
    public function getMinCalibrationPoints(): int
    {
        $minPoints = PHP_INT_MAX;

        foreach ($this->deviceChannels as $channel) {
            $count = 0;
            foreach ($channel->getDevice()->getCalibrations() as $calibration) {
                if ($calibration->getDeviceChannel() === $channel) {
                    $count++;
                }
            }
            $minPoints = min($minPoints, $count);
        }

        return $minPoints === PHP_INT_MAX ? 0 : $minPoints;
    }

    /**
     * Check if this axle group has calibrated channels
     */
    public function isCalibrated(): bool
    {
        return $this->getCalibrationStatus() === 'good';
    }

    public function __toString(): string
    {
        return $this->label ?? $this->name ?? 'Axle Group #' . $this->id;
    }
}
