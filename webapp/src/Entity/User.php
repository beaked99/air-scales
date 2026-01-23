<?php

namespace App\Entity;

use App\Entity\Device;
use App\Entity\Vehicle;
use App\Repository\UserRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;

#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\UniqueConstraint(name: 'UNIQ_IDENTIFIER_EMAIL', fields: ['email'])]
#[ORM\HasLifecycleCallbacks]
class User implements UserInterface, PasswordAuthenticatedUserInterface
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 180)]
    private ?string $email = null;

    #[ORM\Column]
    private array $roles = [];

    #[ORM\Column]
    private ?string $password = null;

    #[ORM\Column(length: 64)]
    private ?string $first_name = null;

    #[ORM\Column(length: 64)]
    private ?string $last_name = null;

    #[ORM\Column]
    private ?\DateTimeImmutable $created_at = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $updated_at = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $last_login = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $stripe_customer_id = null;

    #[ORM\Column(type: 'boolean')]
    private bool $isVerified = false;

    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $verificationToken = null;

    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $resetToken = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $resetTokenExpiresAt = null;

    #[ORM\Column(type: 'boolean', options: ['default' => true])]
    private bool $bleAutoSwitch = true;

    #[ORM\Column(length: 10, options: ['default' => 'psi'])]
    private string $pressureUnit = 'psi';

    #[ORM\Column(length: 10, options: ['default' => 'lbs'])]
    private string $weightUnit = 'lbs';

    #[ORM\Column(type: 'boolean', options: ['default' => true])]
    private bool $autoUpdateFirmware = true;

    #[ORM\OneToMany(targetEntity: Device::class, mappedBy: 'soldTo')]
    private Collection $devices;

    #[ORM\OneToMany(targetEntity: Vehicle::class, mappedBy: 'created_by')]
    private Collection $vehicles;

    #[ORM\OneToMany(targetEntity: Vehicle::class, mappedBy: 'updated_by')]
    private Collection $vehiclesUpdated;

    #[ORM\OneToOne(targetEntity: Subscription::class, mappedBy: 'user', cascade: ['persist', 'remove'])]
    private ?Subscription $subscription = null;

    private ?string $plainPassword = null;

    public function __construct()
    {
        $this->devices = new ArrayCollection();
        $this->vehicles = new ArrayCollection();
        $this->vehiclesUpdated = new ArrayCollection();
        $this->created_at = new \DateTimeImmutable();
    }

    #[ORM\PrePersist]
    public function setCreatedAtValue(): void
    {
        if ($this->created_at === null) {
            $this->created_at = new \DateTimeImmutable();
        }
    }

    #[ORM\PreUpdate]
    public function setUpdatedAtValue(): void
    {
        $this->updated_at = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }

    public function getEmail(): ?string { return $this->email; }

    public function setEmail(string $email): static
    {
        $this->email = $email;
        return $this;
    }

    public function getUserIdentifier(): string
    {
        return (string) $this->email;
    }

    public function getRoles(): array
    {
        $roles = $this->roles;
        $roles[] = 'ROLE_USER';
        return array_unique($roles);
    }

    public function setRoles(array $roles): static
    {
        $this->roles = $roles;
        return $this;
    }

    public function getPlainPassword(): ?string { return $this->plainPassword; }

    public function setPlainPassword(?string $plainPassword): self
    {
        $this->plainPassword = $plainPassword;
        return $this;
    }

    public function getPassword(): ?string { return $this->password; }

    public function setPassword(string $password): self
    {
        $this->password = $password;
        return $this;
    }

    public function eraseCredentials(): void
    {
        $this->plainPassword = null;
    }

    public function getDevices(): Collection { return $this->devices; }

    public function addDevice(Device $device): static
    {
        if (!$this->devices->contains($device)) {
            $this->devices->add($device);
            $device->setSoldTo($this);
        }
        return $this;
    }

    public function removeDevice(Device $device): static
    {
        if ($this->devices->removeElement($device)) {
            if ($device->getSoldTo() === $this) {
                $device->setSoldTo(null);
            }
        }
        return $this;
    }

    public function getFirstName(): ?string { return $this->first_name; }

    public function setFirstName(string $first_name): static
    {
        $this->first_name = $first_name;
        return $this;
    }

    public function getLastName(): ?string { return $this->last_name; }

    public function setLastName(string $last_name): static
    {
        $this->last_name = $last_name;
        return $this;
    }

    public function getFullName(): ?string
    {
        return $this->first_name . ' ' . $this->last_name;
    }

    public function getCreatedAt(): ?\DateTimeImmutable { return $this->created_at; }

    public function setCreatedAt(?\DateTimeImmutable $created_at): static
    {
        $this->created_at = $created_at;
        return $this;
    }

    public function getUpdatedAt(): ?\DateTimeImmutable { return $this->updated_at; }

    public function setUpdatedAt(?\DateTimeImmutable $updated_at): static
    {
        $this->updated_at = $updated_at;
        return $this;
    }

    public function getLastLogin(): ?\DateTimeImmutable { return $this->last_login; }

    public function setLastLogin(?\DateTimeImmutable $last_login): static
    {
        $this->last_login = $last_login;
        return $this;
    }

    public function getStripeCustomerId(): ?string { return $this->stripe_customer_id; }

    public function setStripeCustomerId(?string $stripe_customer_id): static
    {
        $this->stripe_customer_id = $stripe_customer_id;
        return $this;
    }

    public function __toString(): string
    {
        return $this->getEmail() ?? 'Unknown User';
    }

    public function getVehicles(): Collection { return $this->vehicles; }

    public function addVehicle(Vehicle $vehicle): static
    {
        if (!$this->vehicles->contains($vehicle)) {
            $this->vehicles->add($vehicle);
            $vehicle->setCreatedBy($this);
        }
        return $this;
    }

    public function removeVehicle(Vehicle $vehicle): static
    {
        if ($this->vehicles->removeElement($vehicle)) {
            if ($vehicle->getCreatedBy() === $this) {
                $vehicle->setCreatedBy(null);
            }
        }
        return $this;
    }

    public function getVehiclesUpdated(): Collection { return $this->vehiclesUpdated; }

    public function addVehicleUpdated(Vehicle $vehicle): static
    {
        if (!$this->vehiclesUpdated->contains($vehicle)) {
            $this->vehiclesUpdated->add($vehicle);
            $vehicle->setUpdatedBy($this);
        }
        return $this;
    }

    public function removeVehicleUpdated(Vehicle $vehicle): static
    {
        if ($this->vehiclesUpdated->removeElement($vehicle)) {
            if ($vehicle->getUpdatedBy() === $this) {
                $vehicle->setUpdatedBy(null);
            }
        }
        return $this;
    }

    public function getSubscription(): ?Subscription
    {
        return $this->subscription;
    }

    public function setSubscription(?Subscription $subscription): static
    {
        // Unset the owning side of the relation if necessary
        if ($subscription === null && $this->subscription !== null) {
            $this->subscription->setUser(null);
        }

        // Set the owning side of the relation if necessary
        if ($subscription !== null && $subscription->getUser() !== $this) {
            $subscription->setUser($this);
        }

        $this->subscription = $subscription;
        return $this;
    }

    /**
     * Check if user has active subscription access
     */
    public function hasActiveSubscription(): bool
    {
        if (!$this->subscription) {
            return false;
        }

        return $this->subscription->hasActiveAccess();
    }

    public function isVerified(): bool
    {
        return $this->isVerified;
    }

    public function setIsVerified(bool $isVerified): static
    {
        $this->isVerified = $isVerified;
        return $this;
    }

    public function getVerificationToken(): ?string
    {
        return $this->verificationToken;
    }

    public function setVerificationToken(?string $verificationToken): static
    {
        $this->verificationToken = $verificationToken;
        return $this;
    }

    public function getResetToken(): ?string
    {
        return $this->resetToken;
    }

    public function setResetToken(?string $resetToken): static
    {
        $this->resetToken = $resetToken;
        return $this;
    }

    public function getResetTokenExpiresAt(): ?\DateTimeImmutable
    {
        return $this->resetTokenExpiresAt;
    }

    public function setResetTokenExpiresAt(?\DateTimeImmutable $resetTokenExpiresAt): static
    {
        $this->resetTokenExpiresAt = $resetTokenExpiresAt;
        return $this;
    }

    public function isResetTokenValid(): bool
    {
        if (!$this->resetToken || !$this->resetTokenExpiresAt) {
            return false;
        }

        return $this->resetTokenExpiresAt > new \DateTimeImmutable();
    }

    public function getBleAutoSwitch(): bool
    {
        return $this->bleAutoSwitch;
    }

    public function setBleAutoSwitch(bool $bleAutoSwitch): static
    {
        $this->bleAutoSwitch = $bleAutoSwitch;
        return $this;
    }

    public function getPressureUnit(): string
    {
        return $this->pressureUnit;
    }

    public function setPressureUnit(string $pressureUnit): static
    {
        $this->pressureUnit = $pressureUnit;
        return $this;
    }

    public function getWeightUnit(): string
    {
        return $this->weightUnit;
    }

    public function setWeightUnit(string $weightUnit): static
    {
        $this->weightUnit = $weightUnit;
        return $this;
    }

    public function isAutoUpdateFirmware(): bool
    {
        return $this->autoUpdateFirmware;
    }

    public function setAutoUpdateFirmware(bool $autoUpdateFirmware): static
    {
        $this->autoUpdateFirmware = $autoUpdateFirmware;
        return $this;
    }
}