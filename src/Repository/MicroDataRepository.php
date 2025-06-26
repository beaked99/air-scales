<?php

namespace App\Repository;

use App\Entity\MicroData;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<MicroData>
 *
 * @method MicroData|null find($id, $lockMode = null, $lockVersion = null)
 * @method MicroData|null findOneBy(array $criteria, array $orderBy = null)
 * @method MicroData[]    findAll()
 * @method MicroData[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class MicroDataRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MicroData::class);
    }

    // Example custom method to get latest X records for a device
    public function findLatestByDevice(int $deviceId, int $limit = 10): array
    {
        return $this->createQueryBuilder('m')
            ->andWhere('m.device = :deviceId')
            ->setParameter('deviceId', $deviceId)
            ->orderBy('m.timestamp', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }
}
