<?php

namespace App\Controller\Admin;

use App\Entity\Vehicle;
use App\Entity\User;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Filter\EntityFilter;

class VehicleCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return Vehicle::class;
    }

    public function configureFields(string $pageName): iterable
    {
        return [
            IdField::new('id')->hideOnForm(),
            
            // Vehicle details
            IntegerField::new('year'),
            TextField::new('make'),
            TextField::new('model'),
            TextField::new('nickname'),
            TextField::new('vin'),
            TextField::new('license_plate', 'License Plate'),
            
            // Owner field - this is the key part
            AssociationField::new('created_by', 'Owner')
                ->setRequired(false)
                ->autocomplete()
                ->formatValue(function ($value, $entity) {
                    return $value ? $value->getFullName() . ' (' . $value->getEmail() . ')' : 'No Owner';
                }),
            
            // Axle group relationship
            AssociationField::new('axleGroup', 'Axle Group')
                ->setRequired(false)
                ->autocomplete(),
            
            // Timestamps (read-only)
            DateTimeField::new('created_at', 'Created')
                ->hideOnForm(),
            DateTimeField::new('updated_at', 'Updated')
                ->hideOnForm(),
            DateTimeField::new('last_seen', 'Last Seen')
                ->hideOnForm(),
            
            // Updated by (read-only)
            AssociationField::new('updated_by', 'Updated By')
                ->hideOnForm()
                ->formatValue(function ($value, $entity) {
                    return $value ? $value->getFullName() : 'System';
                }),
            
            // Show device count
            AssociationField::new('devices', 'Devices')
                ->hideOnForm()
                ->formatValue(function ($value, $entity) {
                    return $entity->getDevices()->count() . ' device(s)';
                }),
        ];
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Vehicle')
            ->setEntityLabelInPlural('Vehicles')
            ->setSearchFields(['year', 'make', 'model', 'nickname', 'vin', 'license_plate', 'created_by.first_name', 'created_by.last_name', 'created_by.email'])
            ->setDefaultSort(['updated_at' => 'DESC']);
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters
            ->add(EntityFilter::new('created_by', 'Owner'))
            ->add('make')
            ->add('model')
            ->add('year');
    }
}