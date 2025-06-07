<?php

namespace App\DataFixtures;

use App\Entity\User;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use function Symfony\Component\Clock\now;
use Doctrine\ORM\EntityManagerInterface;

class UserFixtures extends Fixture
{
    public function __construct(private UserPasswordHasherInterface $passwordHasher) {}
    
    public function load(ObjectManager $manager): void
    {    
        $user = new User();
        $user->setEmail("kevin@beaker.ca");
        $user->setRoles(['ROLE_ADMIN']);
        $user->setPassword(
            $this->passwordHasher->hashPassword($user, 'admin')
        );
        $user->setFirstName('kevin');
        $user->setLastName('wiebe');    
        $user->setCreatedAt(
            new \DateTimeImmutable('now', new \DateTimeZone('America/Los_Angeles'))
            );
        $manager->persist($user);
        $manager->flush();
    }
}
