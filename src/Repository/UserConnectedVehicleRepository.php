<?php
namespace App\Repository;

use App\Entity\UserConnectedVehicle;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class UserConnectedVehicleRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, UserConnectedVehicle::class);
    }

    /**
     * Get all vehicles this user has marked as currently connected
     */
    public function findConnectedVehiclesForUser(User $user): array
    {
        return $this->createQueryBuilder('ucv')
            ->leftJoin('ucv.vehicle', 'v')
            ->addSelect('v')
            ->where('ucv.user = :user')
            ->andWhere('ucv.isConnected = true')
            ->setParameter('user', $user)
            ->orderBy('ucv.lastChangedAt', 'DESC')
            ->getQuery()
            ->getResult();
    }
}
