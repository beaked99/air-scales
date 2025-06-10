<?php

namespace App\DataFixtures;

use App\Entity\User;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

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
        $this->addReference('user_1', $user);
        
        $user = new User();
        $user->setEmail("test@test.ca");
        $user->setRoles(['ROLE_ADMIN']);
        $user->setPassword(
            $this->passwordHasher->hashPassword($user, 'admin')
        );
        $user->setFirstName('test');
        $user->setLastName('dummies');    
        $user->setCreatedAt(
            new \DateTimeImmutable('now', new \DateTimeZone('America/Los_Angeles'))
        );
        $manager->persist($user);
        $this->addReference('user_2', $user);
        
        $user = new User();
        $user->setEmail("testing@tested.ca");
        $user->setRoles(['ROLE_USER']);
        $user->setPassword(
            $this->passwordHasher->hashPassword($user, 'test1234')
        );
        $user->setFirstName('tested');
        $user->setLastName('for alcohol');    
        $user->setCreatedAt(
            new \DateTimeImmutable('now', new \DateTimeZone('America/Los_Angeles'))
        );
        $manager->persist($user);
        $this->addReference('user_3', $user);
        
        $user = new User();
        $user->setEmail("random@random.ca");
        $user->setRoles(['ROLE_USER']);
        $user->setPassword(
            $this->passwordHasher->hashPassword($user, 'test1234')
        );
        $user->setFirstName('losing');
        $user->setLastName('randomness');    
        $user->setCreatedAt(
            new \DateTimeImmutable('now', new \DateTimeZone('America/Los_Angeles'))
        );
        $manager->persist($user);
        $this->addReference('user_4', $user);
        
        $manager->flush();
    }
}
