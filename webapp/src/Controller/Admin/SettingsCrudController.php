<?php

namespace App\Controller\Admin;

use App\Entity\Settings;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\MoneyField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;

class SettingsCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return Settings::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setPageTitle('index', 'Pricing & Settings')
            ->setPageTitle('edit', 'Edit Setting')
            ->setPageTitle('new', 'New Setting')
            ->setDefaultSort(['setting_key' => 'ASC']);
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->onlyOnIndex();

        yield TextField::new('setting_key', 'Setting Key')
            ->setHelp('Unique identifier for this setting (e.g., device_price_single)')
            ->setRequired(true);

        yield TextField::new('setting_value', 'Value')
            ->setHelp('For prices, enter amount in dollars (e.g., 150.00)')
            ->setRequired(true);

        yield TextareaField::new('description', 'Description')
            ->setHelp('Human-readable description of what this setting controls')
            ->hideOnIndex();

        yield DateTimeField::new('updated_at', 'Last Updated')
            ->onlyOnIndex()
            ->setFormat('MMM d, yyyy h:mm a');
    }
}
