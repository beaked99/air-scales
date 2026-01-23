<?php

namespace App\Controller\Admin;

use App\Entity\Product;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\MoneyField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;

class ProductCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return Product::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Product')
            ->setEntityLabelInPlural('Products')
            ->setDefaultSort(['type' => 'ASC', 'priceUsd' => 'ASC']);
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->onlyOnIndex();

        yield TextField::new('name', 'Product Name');

        yield TextField::new('slug', 'Slug')
            ->setHelp('Unique identifier (e.g., monthly-subscription, device-single)');

        yield ChoiceField::new('type', 'Type')
            ->setChoices([
                'Subscription' => 'subscription',
                'Device' => 'device',
            ])
            ->renderAsBadges([
                'subscription' => 'primary',
                'device' => 'success',
            ]);

        yield ChoiceField::new('billingPeriod', 'Billing Period')
            ->setChoices([
                'Monthly' => 'monthly',
                'Yearly' => 'yearly',
                'One-time' => 'one_time',
            ])
            ->setHelp('For subscriptions: monthly/yearly. For devices: one_time');

        yield MoneyField::new('priceUsd', 'Price')
            ->setCurrency('USD')
            ->setStoredAsCents(false);

        yield TextareaField::new('description', 'Description')
            ->hideOnIndex();

        yield TextField::new('stripePriceId', 'Stripe Price ID')
            ->setHelp('From Stripe Dashboard (e.g., price_xxxxxxxxxxxxx)');

        yield TextField::new('stripeProductId', 'Stripe Product ID')
            ->setHelp('Optional: From Stripe Dashboard (e.g., prod_xxxxxxxxxxxxx)')
            ->hideOnIndex();

        yield BooleanField::new('isActive', 'Active')
            ->setHelp('Only active products are available for purchase');

        yield DateTimeField::new('createdAt', 'Created')
            ->hideOnForm();

        yield DateTimeField::new('updatedAt', 'Updated')
            ->hideOnForm();
    }
}
