<?php
namespace App\Entity;

use App\Repository\UserConnectedVehicleRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: UserConnectedVehicleRepository::class)]
#[ORM\Table(name: 'user_connected_vehicle')]
#[ORM\UniqueConstraint(name: 'user_vehicle_unique', columns: ['user_id', 'vehicle_id'])]
class UserConnectedVehicle
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private ?User $user = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private ?Vehicle $vehicle = null;

    #[ORM\Column(type: 'boolean')]
    private bool $isConnected = true;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $lastChangedAt;

    public function __construct()
    {
        $this->lastChangedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): ?User
    {
        return $this->user;
    }

    public function setUser(?User $user): static
    {
        $this->user = $user;
        return $this;
    }

    public function getVehicle(): ?Vehicle
    {
        return $this->vehicle;
    }

    public function setVehicle(?Vehicle $vehicle): static
    {
        $this->vehicle = $vehicle;
        return $this;
    }

    public function isConnected(): bool
    {
        return $this->isConnected;
    }

    public function setIsConnected(bool $isConnected): static
    {
        $this->isConnected = $isConnected;
        $this->lastChangedAt = new \DateTimeImmutable();
        return $this;
    }

    public function getLastChangedAt(): \DateTimeImmutable
    {
        return $this->lastChangedAt;
    }

    public function setLastChangedAt(\DateTimeImmutable $lastChangedAt): static
    {
        $this->lastChangedAt = $lastChangedAt;
        return $this;
    }
}
