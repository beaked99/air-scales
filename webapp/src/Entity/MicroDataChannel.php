<?php

namespace App\Entity;

use App\Repository\MicroDataChannelRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: MicroDataChannelRepository::class)]
#[ORM\Table(name: 'micro_data_channel')]
#[ORM\UniqueConstraint(name: 'micro_data_channel_unique', columns: ['micro_data_id', 'device_channel_id'])]
class MicroDataChannel
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: MicroData::class, inversedBy: 'microDataChannels')]
    #[ORM\JoinColumn(nullable: false)]
    private ?MicroData $microData = null;

    #[ORM\ManyToOne(targetEntity: DeviceChannel::class, inversedBy: 'microDataChannels')]
    #[ORM\JoinColumn(nullable: false)]
    private ?DeviceChannel $deviceChannel = null;

    #[ORM\Column(type: 'float', nullable: true)]
    private ?float $airPressure = null;

    #[ORM\Column(type: 'float', nullable: true)]
    private ?float $weight = null;

    // Getters and setters
    public function getId(): ?int
    {
        return $this->id;
    }

    public function getMicroData(): ?MicroData
    {
        return $this->microData;
    }

    public function setMicroData(?MicroData $microData): self
    {
        $this->microData = $microData;
        return $this;
    }

    public function getDeviceChannel(): ?DeviceChannel
    {
        return $this->deviceChannel;
    }

    public function setDeviceChannel(?DeviceChannel $deviceChannel): self
    {
        $this->deviceChannel = $deviceChannel;
        return $this;
    }

    public function getAirPressure(): ?float
    {
        return $this->airPressure;
    }

    public function setAirPressure(?float $airPressure): self
    {
        $this->airPressure = $airPressure;
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
}
