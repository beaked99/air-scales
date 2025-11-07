<?php

namespace App\Controller\Admin;

use App\Entity\AxleGroup;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextEditorField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

class AxleGroupCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return AxleGroup::class;
    }

    /*
    public function configureFields(string $pageName): iterable
    {
        return [
            IdField::new('id'),
            TextField::new('name'),
            TextEditorField::new('label'),
        ];
    }
    */
}
