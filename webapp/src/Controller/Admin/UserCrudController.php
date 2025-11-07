<?php

namespace App\Controller\Admin;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateField;
use EasyCorp\Bundle\EasyAdminBundle\Field\EmailField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use EasyCorp\Bundle\EasyAdminBundle\Dto\SearchDto;
use EasyCorp\Bundle\EasyAdminBundle\Dto\EntityDto;
use EasyCorp\Bundle\EasyAdminBundle\Collection\FieldCollection;
use EasyCorp\Bundle\EasyAdminBundle\Collection\FilterCollection;
use Doctrine\ORM\QueryBuilder;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;

class UserCrudController extends AbstractCrudController
{
    private UserPasswordHasherInterface $passwordHasher;

    public function __construct(UserPasswordHasherInterface $passwordHasher)
    {
        $this->passwordHasher = $passwordHasher;
    }

    public static function getEntityFqcn(): string
    {
        return User::class;
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new("id")
            ->setLabel('ID')
            ->onlyOnIndex(); 

        yield EmailField::new('email', 'Email');
        yield TextField::new('first_name', 'First Name');
        yield TextField::new('last_name', 'Last Name');
        yield TextField::new('plainPassword')
            ->setLabel('Password')
            ->setFormType(PasswordType::class)
            ->setRequired($pageName === Crud::PAGE_NEW)
            ->onlyOnForms();

        if ($this->isGranted('ROLE_ADMIN')) {
            $roles = ['ROLE_ADMIN','ROLE_MODERATOR','ROLE_USER'];
            yield ChoiceField::new('roles')
                ->setLabel('Roles')
                ->setChoices(array_combine($roles, $roles))
                ->allowMultipleChoices()
                ->renderExpanded()
                ->renderAsBadges();
        }

        yield DateField::new('created_at')
            ->setLabel('Created On')
            ->hideOnForm();
    }

    
    public function persistEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
        if (!$entityInstance instanceof User) return;

        if (!$entityInstance->getCreatedAt()) {
            $entityInstance->setCreatedAt(new \DateTimeImmutable('now', new \DateTimeZone('America/Los_Angeles')));
        }
            
        if ($entityInstance->getPlainPassword()) {
            $entityInstance->setPassword($this->passwordHasher->hashPassword(
                $entityInstance,
                $entityInstance->getPlainPassword()
            ));
        }

        parent::persistEntity($entityManager, $entityInstance);
    }

    public function updateEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
        if (!$entityInstance instanceof User) return;

        if ($entityInstance->getPlainPassword()) {
            $entityInstance->setPassword($this->passwordHasher->hashPassword(
                $entityInstance,
                $entityInstance->getPlainPassword()
            ));
        }

        parent::updateEntity($entityManager, $entityInstance);
    }
    public function configureCrud(Crud $crud): Crud
    {
        return $crud
        ->setDefaultSort(['id' => 'DESC']);
    }


public function createIndexQueryBuilder(SearchDto $searchDto, EntityDto $entityDto, FieldCollection $fields, FilterCollection $filters): QueryBuilder
{
    $qb = parent::createIndexQueryBuilder($searchDto, $entityDto, $fields, $filters);

    if (!$this->isGranted('ROLE_ADMIN')) {
        $user = $this->getUser();
        
        // Make sure we have a User entity with getId() method
        if ($user instanceof \App\Entity\User) {
            $qb->andWhere('entity.id = :id')
               ->setParameter('id', $user->getId());
        }
    }

    return $qb;
}
    public function configureActions(Actions $actions): Actions
    {
        if (!$this->isGranted('ROLE_ADMIN')) {
            return $actions
                ->disable(Action::NEW, Action::DELETE);
        }

        return $actions;
    }

}