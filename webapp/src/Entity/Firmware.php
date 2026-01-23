<?php

namespace App\Entity;

use App\Entity\Traits\TimestampableTrait;
use App\Repository\FirmwareRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\HasLifecycleCallbacks]
#[ORM\Entity(repositoryClass: FirmwareRepository::class)]
class Firmware
{
    use TimestampableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 32)]
    private ?string $version = null;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $deviceType = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $changelog = null;

    #[ORM\Column(length: 255)]
    private ?string $filename = null;

    #[ORM\Column]
    private ?int $fileSize = null;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $checksum = null;

    #[ORM\Column(type: 'boolean')]
    private bool $isStable = false;

    #[ORM\Column(type: 'boolean')]
    private bool $isDeprecated = false;

    #[ORM\Column(length: 32, nullable: true)]
    private ?string $minimumPreviousVersion = null;

    #[ORM\Column]
    private int $downloadCount = 0;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $releasedAt = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getVersion(): ?string
    {
        return $this->version;
    }

    public function setVersion(string $version): static
    {
        $this->version = $version;
        return $this;
    }

    public function getDeviceType(): ?string
    {
        return $this->deviceType;
    }

    public function setDeviceType(?string $deviceType): static
    {
        $this->deviceType = $deviceType;
        return $this;
    }

    public function getChangelog(): ?string
    {
        return $this->changelog;
    }

    public function setChangelog(?string $changelog): static
    {
        $this->changelog = $changelog;
        return $this;
    }

    public function getFilename(): ?string
    {
        return $this->filename;
    }

    public function setFilename(string $filename): static
    {
        $this->filename = $filename;
        return $this;
    }

    public function getFileSize(): ?int
    {
        return $this->fileSize;
    }

    public function setFileSize(int $fileSize): static
    {
        $this->fileSize = $fileSize;
        return $this;
    }

    public function getChecksum(): ?string
    {
        return $this->checksum;
    }

    public function setChecksum(?string $checksum): static
    {
        $this->checksum = $checksum;
        return $this;
    }

    public function isStable(): bool
    {
        return $this->isStable;
    }

    public function setIsStable(bool $isStable): static
    {
        $this->isStable = $isStable;
        return $this;
    }

    public function isDeprecated(): bool
    {
        return $this->isDeprecated;
    }

    public function setIsDeprecated(bool $isDeprecated): static
    {
        $this->isDeprecated = $isDeprecated;
        return $this;
    }

    public function getMinimumPreviousVersion(): ?string
    {
        return $this->minimumPreviousVersion;
    }

    public function setMinimumPreviousVersion(?string $minimumPreviousVersion): static
    {
        $this->minimumPreviousVersion = $minimumPreviousVersion;
        return $this;
    }

    public function getDownloadCount(): int
    {
        return $this->downloadCount;
    }

    public function setDownloadCount(int $downloadCount): static
    {
        $this->downloadCount = $downloadCount;
        return $this;
    }

    public function incrementDownloadCount(): static
    {
        $this->downloadCount++;
        return $this;
    }

    public function getReleasedAt(): ?\DateTimeImmutable
    {
        return $this->releasedAt;
    }

    public function setReleasedAt(?\DateTimeImmutable $releasedAt): static
    {
        $this->releasedAt = $releasedAt;
        return $this;
    }

    /**
     * Get the storage path for firmware files
     */
    public static function getStoragePath(): string
    {
        return 'firmware';
    }

    /**
     * Get the full file path
     */
    public function getFilePath(): string
    {
        return self::getStoragePath() . '/' . $this->filename;
    }

    /**
     * Get human-readable file size
     */
    public function getFileSizeFormatted(): string
    {
        $bytes = $this->fileSize ?? 0;
        if ($bytes >= 1048576) {
            return number_format($bytes / 1048576, 2) . ' MB';
        }
        if ($bytes >= 1024) {
            return number_format($bytes / 1024, 2) . ' KB';
        }
        return $bytes . ' bytes';
    }

    public function __toString(): string
    {
        return sprintf('v%s%s', $this->version ?? '?', $this->deviceType ? " ({$this->deviceType})" : '');
    }
}
