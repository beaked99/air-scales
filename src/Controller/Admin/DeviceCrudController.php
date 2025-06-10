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
            ->setSearchFields(['serial_number', 'mac_address', 'device_type', 'firmware_version', 'tracking_id'])
            ->setDefaultSort(['id' => 'DESC'])
            ->setPaginatorPageSize(25);
    }

    public function configureFields(string $pageName): iterable
    {
        return [
            IdField::new('id')
                ->hideOnForm(),
            
            TextField::new('serial_number', 'Serial Number')
                ->setRequired(true)
                ->setColumns('col-sm-6'),
            
            TextField::new('mac_address', 'MAC Address')
                ->setRequired(true)
                ->setColumns('col-sm-6')
                ->setHelp('Format: AA:BB:CC:DD:EE:FF'),
            
            TextField::new('device_type', 'Device Type')
                ->setRequired(true)
                ->setColumns('col-sm-6'),
            
            TextField::new('firmware_version', 'Firmware Version')
                ->setRequired(true)
                ->setColumns('col-sm-6'),
            
            AssociationField::new('sold_to', 'Sold To')
                ->setRequired(false)
                ->formatValue(function ($value, $entity) {
                    if ($value) {
                        return $value->getFirstName() . ' ' . $value->getLastName() . ' (' . $value->getEmail() . ')';
                    }
                    return 'Not Assigned';
                })
                ->setColumns('col-sm-12'),
            
            DateTimeField::new('order_date', 'Order Date')
                ->setRequired(false)
                ->setColumns('col-sm-6')
                ->hideOnIndex(),
            
            DateTimeField::new('ship_date', 'Ship Date')
                ->setRequired(false)
                ->setColumns('col-sm-6')
                ->hideOnIndex(),
            
            TextField::new('tracking_id', 'Tracking ID')
                ->setRequired(false)
                ->setColumns('col-sm-6')
                ->hideOnIndex(),
            
            TextareaField::new('notes', 'Notes')
                ->setRequired(false)
                ->setNumOfRows(4)
                ->hideOnIndex()
                ->setColumns('col-sm-12'),
            
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
            ->add(TextFilter::new('serial_number', 'Serial Number'))
            ->add(TextFilter::new('device_type', 'Device Type'))
            ->add(TextFilter::new('firmware_version', 'Firmware Version'))
            ->add(EntityFilter::new('sold_to', 'Sold To'))
            ->add(DateTimeFilter::new('order_date', 'Order Date'))
            ->add(DateTimeFilter::new('ship_date', 'Ship Date'));
    }
}