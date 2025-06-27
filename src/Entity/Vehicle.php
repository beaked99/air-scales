<?php
// Vehicle.php
namespace App\Entity;

use App\Repository\VehicleRepository;
use App\Entity\Traits\TimestampableTrait;
use Doctrine\ORM\Mapping as ORM;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;

#[ORM\Entity(repositoryClass: VehicleRepository::class)]
#[ORM\HasLifecycleCallbacks]
class Vehicle
{
    use TimestampableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\OneToMany(mappedBy: 'vehicle', targetEntity: Device::class)]
    private Collection $devices;

    #[ORM\Column]
    private ?int $year = null;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $make = null;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $model = null;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $nickname = null;

    #[ORM\Column(length: 17, nullable: true)]
    private ?string $vin = null;

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $license_plate = null;

    #[ORM\ManyToOne(inversedBy: 'vehicles')]
    private ?User $created_by = null;

    #[ORM\ManyToOne(inversedBy: 'vehiclesUpdated')]
    private ?User $updated_by = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $last_seen = null;

    #[ORM\ManyToOne]
    private ?AxleGroup $axleGroup = null;

    public function __construct()
    {
        $this->devices = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    /**
     * @return Collection<int, Device>
     */
    public function getDevices(): Collection
    {
        return $this->devices;
    }

    public function addDevice(Device $device): static
    {
        if (!$this->devices->contains($device)) {
            $this->devices->add($device);
            $device->setVehicle($this);
        }

        return $this;
    }

    public function removeDevice(Device $device): static
    {
        if ($this->devices->removeElement($device)) {
            // set the owning side to null (unless already changed)
            if ($device->getVehicle() === $this) {
                $device->setVehicle(null);
            }
        }

        return $this;
    }
    public function getAxleGroup(): ?AxleGroup { return $this->axleGroup; }
    public function setAxleGroup(?AxleGroup $axleGroup): self {
        $this->axleGroup = $axleGroup;
        return $this;
    }   
    
    public function getYear(): ?int
    {
        return $this->year;
    }

    public function setYear(int $year): static
    {
        $this->year = $year;

        return $this;
    }

    public function getMake(): ?string
    {
        return $this->make;
    }

    public function setMake(?string $make): static
    {
        $this->make = $make;

        return $this;
    }

    public function getModel(): ?string
    {
        return $this->model;
    }

    public function setModel(?string $model): static
    {
        $this->model = $model;

        return $this;
    }

    public function getNickname(): ?string
    {
        return $this->nickname;
    }

    public function setNickname(?string $nickname): static
    {
        $this->nickname = $nickname;

        return $this;
    }

    public function getVin(): ?string
    {
        return $this->vin;
    }

    public function setVin(?string $vin): static
    {
        $this->vin = $vin;

        return $this;
    }

    public function getLicensePlate(): ?string
    {
        return $this->license_plate;
    }

    public function setLicensePlate(?string $license_plate): static
    {
        $this->license_plate = $license_plate;

        return $this;
    }

    public function getCreatedBy(): ?User
    {
        return $this->created_by;
    }

    public function setCreatedBy(?User $created_by): static
    {
        $this->created_by = $created_by;

        return $this;
    }

    public function getUpdatedBy(): ?User
    {
        return $this->updated_by;
    }

    public function setUpdatedBy(?User $updated_by): static
    {
        $this->updated_by = $updated_by;

        return $this;
    }

    public function getLastSeen(): ?\DateTimeImmutable
    {
        return $this->last_seen;
    }

    public function setLastSeen(?\DateTimeImmutable $last_seen): static
    {
        $this->last_seen = $last_seen;

        return $this;
    }

    public function __toString(): string
    {
        $display = '';
        if ($this->year) {
            $display .= $this->year . ' ';
        }
        if ($this->make) {
            $display .= $this->make . ' ';
        }
        if ($this->model) {
            $display .= $this->model;
        }
        
        return trim($display) ?: 'Vehicle #' . $this->getId();
    }
}