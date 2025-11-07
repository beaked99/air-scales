<?php

namespace App\Controller\Admin;

use App\Entity\Calibration;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\NumberField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

class CalibrationCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return Calibration::class;
    }

    public function configureFields(string $pageName): iterable
    {
        return [
            IdField::new('id')->onlyOnIndex(),

            //AssociationField::new('device'),
            AssociationField::new('device')
                ->formatValue(function ($value, $entity) {
                    if ($entity && $entity->getDevice() && $entity->getDevice()->getVehicle()) {
                        return $entity->getDevice()->getVehicle()->__toString();
                    }
                    return 'No Vehicle Assigned';
                })
                ->setFormTypeOption('disabled', true),
            AssociationField::new('created_by')->onlyOnDetail(),
            AssociationField::new('updated_by')->onlyOnDetail(),

            NumberField::new('air_pressure'),
            NumberField::new('ambient_air_pressure'),
            NumberField::new('air_temperature'),
            NumberField::new('elevation'),
            NumberField::new('scale_weight'),

            TextField::new('comment')->hideOnIndex(),

            DateTimeField::new('created_at')
                ->setFormTypeOption('disabled', true),
                //->onlyOnDetail(),

            DateTimeField::new('updated_at')
                ->setFormTypeOption('disabled', true),
                //->onlyOnDetail(),
        ];
    }
}
