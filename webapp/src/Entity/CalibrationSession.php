<?php

namespace App\Entity;

use App\Entity\Traits\TimestampableTrait;
use App\Repository\CalibrationSessionRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: CalibrationSessionRepository::class)]
#[ORM\HasLifecycleCallbacks]
#[ORM\Table(name: 'calibration_session')]
class CalibrationSession
{
    use TimestampableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: TruckConfiguration::class)]
    #[ORM\JoinColumn(nullable: true)]
    private ?TruckConfiguration $truckConfiguration = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ?User $createdBy = null;

    #[ORM\Column(type: 'string', length: 50)]
    private string $source = 'MANUAL'; // MANUAL, TRUCK_SCALE, WIZARD

    #[ORM\Column(type: 'string', length: 100, nullable: true)]
    private ?string $ticketNumber = null;

    #[ORM\Column(type: 'datetime')]
    private ?\DateTimeInterface $occurredAt = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $notes = null;

    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $location = null; // Name of scale location

    #[ORM\OneToMany(mappedBy: 'calibrationSession', targetEntity: Calibration::class, cascade: ['persist'])]
    private Collection $calibrations;

    public function __construct()
    {
        $this->calibrations = new ArrayCollection();
        $this->occurredAt = new \DateTime();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTruckConfiguration(): ?TruckConfiguration
    {
        return $this->truckConfiguration;
    }

    public function setTruckConfiguration(?TruckConfiguration $truckConfiguration): self
    {
        $this->truckConfiguration = $truckConfiguration;
        return $this;
    }

    public function getCreatedBy(): ?User
    {
        return $this->createdBy;
    }

    public function setCreatedBy(?User $createdBy): self
    {
        $this->createdBy = $createdBy;
        return $this;
    }

    public function getSource(): string
    {
        return $this->source;
    }

    public function setSource(string $source): self
    {
        $this->source = $source;
        return $this;
    }

    public function getTicketNumber(): ?string
    {
        return $this->ticketNumber;
    }

    public function setTicketNumber(?string $ticketNumber): self
    {
        $this->ticketNumber = $ticketNumber;
        return $this;
    }

    public function getOccurredAt(): ?\DateTimeInterface
    {
        return $this->occurredAt;
    }

    public function setOccurredAt(\DateTimeInterface $occurredAt): self
    {
        $this->occurredAt = $occurredAt;
        return $this;
    }

    public function getNotes(): ?string
    {
        return $this->notes;
    }

    public function setNotes(?string $notes): self
    {
        $this->notes = $notes;
        return $this;
    }

    public function getLocation(): ?string
    {
        return $this->location;
    }

    public function setLocation(?string $location): self
    {
        $this->location = $location;
        return $this;
    }

    /**
     * @return Collection<int, Calibration>
     */
    public function getCalibrations(): Collection
    {
        return $this->calibrations;
    }

    public function addCalibration(Calibration $calibration): self
    {
        if (!$this->calibrations->contains($calibration)) {
            $this->calibrations->add($calibration);
            $calibration->setCalibrationSession($this);
        }
        return $this;
    }

    public function removeCalibration(Calibration $calibration): self
    {
        if ($this->calibrations->removeElement($calibration)) {
            if ($calibration->getCalibrationSession() === $this) {
                $calibration->setCalibrationSession(null);
            }
        }
        return $this;
    }

    public function __toString(): string
    {
        $display = ucfirst(strtolower($this->source));
        if ($this->ticketNumber) {
            $display .= ' #' . $this->ticketNumber;
        }
        if ($this->occurredAt) {
            $display .= ' - ' . $this->occurredAt->format('Y-m-d H:i');
        }
        return $display;
    }
}
