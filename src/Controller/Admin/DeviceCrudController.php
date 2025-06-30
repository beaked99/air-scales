<?php

namespace App\Controller\Admin;

use App\Entity\Device;
use App\Entity\User;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Filter\EntityFilter;
use EasyCorp\Bundle\EasyAdminBundle\Filter\TextFilter;
use EasyCorp\Bundle\EasyAdminBundle\Filter\DateTimeFilter;

class DeviceCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return Device::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Device')
            ->setEntityLabelInPlural('Devices')
            ->setSearchFields(['serialNumber', 'macAddress', 'deviceType', 'firmwareVersion', 'trackingId'])
            ->setDefaultSort(['id' => 'DESC'])
            ->setPaginatorPageSize(25);
    }

    public function configureFields(string $pageName): iterable
    {
        return [
            IdField::new('id')
                ->hideOnForm(),
            
            TextField::new('serialNumber', 'Serial Number')
                ->setRequired(true),
            
            TextField::new('macAddress', 'MAC Address')
                ->setRequired(true)
                ->setHelp('Format: AA:BB:CC:DD:EE:FF'),
            
            TextField::new('deviceType', 'Device Type')
                ->setRequired(true),
            
            TextField::new('firmwareVersion', 'Firmware Version')
                ->setRequired(true),
            
            AssociationField::new('soldTo', 'Sold To')
                ->setRequired(false)
                ->formatValue(function ($value, $entity) {
                    if ($value) {
                        return $value->getFirstName() . ' ' . $value->getLastName() . ' (' . $value->getEmail() . ')';
                    }
                    return 'Not Assigned';
                }),
            
            DateTimeField::new('orderDate', 'Order Date')
                ->setRequired(false)
                ->hideOnIndex(),
            
            DateTimeField::new('shipDate', 'Ship Date')
                ->setRequired(false)
                ->hideOnIndex(),
            
            TextField::new('trackingId', 'Tracking ID')
                ->setRequired(false)
                ->hideOnIndex(),
            
            TextareaField::new('notes', 'Notes')
                ->setRequired(false)
                ->setNumOfRows(4)
                ->hideOnIndex(),
            
            DateTimeField::new('createdAt', 'Created At')
                ->hideOnForm()
                ->hideOnIndex(),
            
            DateTimeField::new('updatedAt', 'Updated At')
                ->hideOnForm()
                ->hideOnIndex(),
        ];
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters
            ->add(TextFilter::new('serialNumber', 'Serial Number'))
            ->add(TextFilter::new('deviceType', 'Device Type'))
            ->add(TextFilter::new('firmwareVersion', 'Firmware Version'))
            ->add(EntityFilter::new('soldTo', 'Sold To'))
            ->add(DateTimeFilter::new('orderDate', 'Order Date'))
            ->add(DateTimeFilter::new('shipDate', 'Ship Date'));
    }
}