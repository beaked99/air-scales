<?php

namespace App\Repository;

use App\Entity\Firmware;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Firmware>
 */
class FirmwareRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Firmware::class);
    }

    /**
     * Find the latest stable firmware for a device type
     */
    public function findLatestStable(?string $deviceType = null): ?Firmware
    {
        $qb = $this->createQueryBuilder('f')
            ->andWhere('f.isStable = :stable')
            ->andWhere('f.isDeprecated = :deprecated')
            ->setParameter('stable', true)
            ->setParameter('deprecated', false)
            ->orderBy('f.releasedAt', 'DESC')
            ->setMaxResults(1);

        if ($deviceType) {
            $qb->andWhere('f.deviceType = :deviceType OR f.deviceType IS NULL')
               ->setParameter('deviceType', $deviceType);
        }

        return $qb->getQuery()->getOneOrNullResult();
    }

    /**
     * Find all non-deprecated firmware versions for a device type
     */
    public function findAvailableVersions(?string $deviceType = null): array
    {
        $qb = $this->createQueryBuilder('f')
            ->andWhere('f.isDeprecated = :deprecated')
            ->setParameter('deprecated', false)
            ->orderBy('f.releasedAt', 'DESC');

        if ($deviceType) {
            $qb->andWhere('f.deviceType = :deviceType OR f.deviceType IS NULL')
               ->setParameter('deviceType', $deviceType);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Check if an update is available for a given version
     */
    public function findUpdateFor(string $currentVersion, ?string $deviceType = null): ?Firmware
    {
        $latest = $this->findLatestStable($deviceType);

        if (!$latest) {
            return null;
        }

        // Compare versions (simple string comparison, assumes semver)
        if (version_compare($latest->getVersion(), $currentVersion, '>')) {
            // Check minimum version requirement
            $minVersion = $latest->getMinimumPreviousVersion();
            if ($minVersion && version_compare($currentVersion, $minVersion, '<')) {
                return null; // Current version too old for direct update
            }
            return $latest;
        }

        return null;
    }
}
