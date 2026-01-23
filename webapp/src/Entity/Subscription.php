<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'subscription')]
#[ORM\HasLifecycleCallbacks]
class Subscription
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\OneToOne(targetEntity: User::class, inversedBy: 'subscription')]
    #[ORM\JoinColumn(nullable: false)]
    private ?User $user = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $stripe_subscription_id = null;

    #[ORM\Column(length: 50)]
    private string $status = 'inactive'; // active, past_due, canceled, trialing, inactive

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $plan_type = null; // monthly, yearly

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $scheduled_plan_type = null; // monthly, yearly (what will activate at next renewal)

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $scheduled_stripe_price_id = null; // Stripe price ID that will activate at next renewal

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $scheduled_change_effective_at = null; // When the scheduled change takes effect

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $current_period_start = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $current_period_end = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $trial_ends_at = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $canceled_at = null;

    #[ORM\Column]
    private ?\DateTimeImmutable $created_at = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $updated_at = null;

    // 6 months free trial for device purchasers
    #[ORM\Column]
    private bool $has_device_trial = false;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $device_trial_ends_at = null;

    // Legacy/promotional users get unlimited free access
    #[ORM\Column]
    private bool $is_promotional = false;

    #[ORM\PrePersist]
    public function onPrePersist(): void
    {
        $this->created_at = new \DateTimeImmutable();
    }

    #[ORM\PreUpdate]
    public function onPreUpdate(): void
    {
        $this->updated_at = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): ?User
    {
        return $this->user;
    }

    public function setUser(User $user): static
    {
        $this->user = $user;
        return $this;
    }

    public function getStripeSubscriptionId(): ?string
    {
        return $this->stripe_subscription_id;
    }

    public function setStripeSubscriptionId(?string $stripe_subscription_id): static
    {
        $this->stripe_subscription_id = $stripe_subscription_id;
        return $this;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): static
    {
        $this->status = $status;
        return $this;
    }

    public function getPlanType(): ?string
    {
        return $this->plan_type;
    }

    public function setPlanType(?string $plan_type): static
    {
        $this->plan_type = $plan_type;
        return $this;
    }

    public function getScheduledPlanType(): ?string
    {
        return $this->scheduled_plan_type;
    }

    public function setScheduledPlanType(?string $scheduled_plan_type): static
    {
        $this->scheduled_plan_type = $scheduled_plan_type;
        return $this;
    }

    public function getScheduledStripePriceId(): ?string
    {
        return $this->scheduled_stripe_price_id;
    }

    public function setScheduledStripePriceId(?string $scheduled_stripe_price_id): static
    {
        $this->scheduled_stripe_price_id = $scheduled_stripe_price_id;
        return $this;
    }

    public function getScheduledChangeEffectiveAt(): ?\DateTimeImmutable
    {
        return $this->scheduled_change_effective_at;
    }

    public function setScheduledChangeEffectiveAt(?\DateTimeImmutable $scheduled_change_effective_at): static
    {
        $this->scheduled_change_effective_at = $scheduled_change_effective_at;
        return $this;
    }

    public function getCurrentPeriodStart(): ?\DateTimeImmutable
    {
        return $this->current_period_start;
    }

    public function setCurrentPeriodStart(?\DateTimeImmutable $current_period_start): static
    {
        $this->current_period_start = $current_period_start;
        return $this;
    }

    public function getCurrentPeriodEnd(): ?\DateTimeImmutable
    {
        return $this->current_period_end;
    }

    public function setCurrentPeriodEnd(?\DateTimeImmutable $current_period_end): static
    {
        $this->current_period_end = $current_period_end;
        return $this;
    }

    public function getTrialEndsAt(): ?\DateTimeImmutable
    {
        return $this->trial_ends_at;
    }

    public function setTrialEndsAt(?\DateTimeImmutable $trial_ends_at): static
    {
        $this->trial_ends_at = $trial_ends_at;
        return $this;
    }

    public function getCanceledAt(): ?\DateTimeImmutable
    {
        return $this->canceled_at;
    }

    public function setCanceledAt(?\DateTimeImmutable $canceled_at): static
    {
        $this->canceled_at = $canceled_at;
        return $this;
    }

    public function getCreatedAt(): ?\DateTimeImmutable
    {
        return $this->created_at;
    }

    public function setCreatedAt(\DateTimeImmutable $created_at): static
    {
        $this->created_at = $created_at;
        return $this;
    }

    public function getUpdatedAt(): ?\DateTimeImmutable
    {
        return $this->updated_at;
    }

    public function setUpdatedAt(?\DateTimeImmutable $updated_at): static
    {
        $this->updated_at = $updated_at;
        return $this;
    }

    public function hasDeviceTrial(): bool
    {
        return $this->has_device_trial;
    }

    public function setHasDeviceTrial(bool $has_device_trial): static
    {
        $this->has_device_trial = $has_device_trial;
        return $this;
    }

    public function getDeviceTrialEndsAt(): ?\DateTimeImmutable
    {
        return $this->device_trial_ends_at;
    }

    public function setDeviceTrialEndsAt(?\DateTimeImmutable $device_trial_ends_at): static
    {
        $this->device_trial_ends_at = $device_trial_ends_at;
        return $this;
    }

    public function isPromotional(): bool
    {
        return $this->is_promotional;
    }

    public function setIsPromotional(bool $is_promotional): static
    {
        $this->is_promotional = $is_promotional;
        return $this;
    }

    /**
     * Check if user has active subscription access (including trials and promotional)
     */
    public function hasActiveAccess(): bool
    {
        // Promotional users always have access
        if ($this->is_promotional) {
            return true;
        }

        // Check device trial (6 months from purchase)
        if ($this->has_device_trial && $this->device_trial_ends_at) {
            if ($this->device_trial_ends_at > new \DateTimeImmutable()) {
                return true;
            }
        }

        // Check paid subscription (no grace period)
        if (in_array($this->status, ['active', 'trialing'])) {
            return true;
        }

        // No grace period - access ends at current_period_end
        return false;
    }

    /**
     * Get days remaining until access expires
     */
    public function getDaysUntilExpiration(): ?int
    {
        if ($this->is_promotional) {
            return null; // Never expires
        }

        $now = new \DateTimeImmutable();

        // Check device trial
        if ($this->has_device_trial && $this->device_trial_ends_at) {
            if ($this->device_trial_ends_at > $now) {
                return $now->diff($this->device_trial_ends_at)->days;
            }
        }

        // Check subscription end (no grace period)
        if ($this->current_period_end) {
            if ($this->current_period_end > $now) {
                return $now->diff($this->current_period_end)->days;
            }
        }

        return 0;
    }
}
