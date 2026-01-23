<?php

namespace App\Controller\Admin;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\EmailField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use EasyCorp\Bundle\EasyAdminBundle\Dto\SearchDto;
use EasyCorp\Bundle\EasyAdminBundle\Dto\EntityDto;
use EasyCorp\Bundle\EasyAdminBundle\Collection\FieldCollection;
use EasyCorp\Bundle\EasyAdminBundle\Collection\FilterCollection;
use Doctrine\ORM\QueryBuilder;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\KeyValueStore;
use EasyCorp\Bundle\EasyAdminBundle\Context\AdminContext;

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

        // === SUBSCRIPTION MANAGEMENT (Admin Only) ===
        if ($this->isGranted('ROLE_ADMIN') && $pageName !== Crud::PAGE_NEW) {

            // Display current status on index page
            if ($pageName === Crud::PAGE_INDEX) {
                yield TextField::new('subscriptionStatus', 'Subscription')
                    ->setLabel('Subscription Status')
                    ->formatValue(function ($value, $entity) {
                        $subscription = $entity->getSubscription();
                        if (!$subscription) {
                            return '<span class="badge badge-warning">No Subscription</span>';
                        }

                        if ($subscription->isPromotional()) {
                            return '<span class="badge badge-info">Legacy/Promotional</span>';
                        }

                        if ($subscription->hasDeviceTrial() && $subscription->getDeviceTrialEndsAt()) {
                            $days = $subscription->getDaysUntilExpiration();
                            return '<span class="badge badge-primary">Device Trial (' . $days . ' days left)</span>';
                        }

                        $status = $subscription->getStatus();
                        $badge = match($status) {
                            'active' => 'badge-success',
                            'trialing' => 'badge-primary',
                            'canceled' => 'badge-warning',
                            'past_due' => 'badge-danger',
                            default => 'badge-secondary'
                        };

                        return '<span class="badge ' . $badge . '">' . ucfirst($status) . '</span>';
                    });

                yield DateTimeField::new('subscriptionExpiry', 'Expires/Renews')
                    ->setLabel('Subscription Expiry')
                    ->formatValue(function ($value, $entity) {
                        $subscription = $entity->getSubscription();
                        if (!$subscription) {
                            return '-';
                        }

                        if ($subscription->isPromotional()) {
                            return 'Never';
                        }

                        $days = $subscription->getDaysUntilExpiration();
                        if ($days === null) {
                            return 'Never';
                        }

                        if ($subscription->getDeviceTrialEndsAt()) {
                            return $subscription->getDeviceTrialEndsAt()->format('M j, Y') . ' (' . $days . ' days)';
                        }

                        if ($subscription->getCurrentPeriodEnd()) {
                            return $subscription->getCurrentPeriodEnd()->format('M j, Y') . ' (' . $days . ' days)';
                        }

                        return '-';
                    });
            }

            // Editable subscription fields on edit/detail page
            if ($pageName === Crud::PAGE_EDIT || $pageName === Crud::PAGE_DETAIL) {
                // Note: Subscription entity will be auto-created in updateEntity if it doesn't exist
                // So these fields are safe to show - they'll just create a new subscription on save

                yield BooleanField::new('subscription.isPromotional', 'Legacy/Promotional Access')
                    ->setHelp('Give this user unlimited free access forever')
                    ->setFormTypeOption('required', false);

                yield BooleanField::new('subscription.hasDeviceTrial', 'Device Trial')
                    ->setHelp('6-month trial included with device purchase')
                    ->setFormTypeOption('required', false);

                yield DateTimeField::new('subscription.deviceTrialEndsAt', 'Trial Expires')
                    ->setHelp('When the device trial ends')
                    ->setFormTypeOption('required', false);

                yield ChoiceField::new('subscription.status', 'Subscription Status')
                    ->setChoices([
                        'Inactive' => 'inactive',
                        'Active' => 'active',
                        'Trialing' => 'trialing',
                        'Canceled' => 'canceled',
                        'Past Due' => 'past_due',
                    ])
                    ->setHelp('Current subscription status')
                    ->setFormTypeOption('required', false);

                yield ChoiceField::new('subscription.planType', 'Plan Type')
                    ->setChoices([
                        'None' => null,
                        'Monthly' => 'monthly',
                        'Yearly' => 'yearly',
                    ])
                    ->setHelp('Monthly ($5/mo) or Yearly ($50/yr)')
                    ->setFormTypeOption('required', false);

                yield DateTimeField::new('subscription.currentPeriodEnd', 'Period Ends')
                    ->setHelp('When current billing period ends')
                    ->setFormTypeOption('required', false);
            }
        }
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

    /**
     * Called when loading an entity for edit/detail.
     * Create subscription if it doesn't exist to avoid NULL errors in form.
     */
    public function createEditForm(EntityDto $entityDto, \EasyCorp\Bundle\EasyAdminBundle\Config\KeyValueStore $formOptions, \EasyCorp\Bundle\EasyAdminBundle\Context\AdminContext $context): \Symfony\Component\Form\FormInterface
    {
        $user = $entityDto->getInstance();

        if ($user instanceof User && !$user->getSubscription()) {
            $em = $this->container->get('doctrine')->getManager();
            $subscription = new \App\Entity\Subscription();
            $subscription->setUser($user);
            $subscription->setStatus('inactive');
            $subscription->setCreatedAt(new \DateTimeImmutable());
            $em->persist($subscription);
            $user->setSubscription($subscription);
            $em->flush();
        }

        return parent::createEditForm($entityDto, $formOptions, $context);
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

        // Ensure user has a subscription entity (create if doesn't exist)
        if (!$entityInstance->getSubscription()) {
            $subscription = new \App\Entity\Subscription();
            $subscription->setUser($entityInstance);
            $subscription->setStatus('inactive');
            $entityManager->persist($subscription);
            $entityInstance->setSubscription($subscription);
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

    // Always left join subscription to avoid N+1 queries and ensure it's loaded
    $qb->leftJoin('entity.subscription', 's')
       ->addSelect('s');

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