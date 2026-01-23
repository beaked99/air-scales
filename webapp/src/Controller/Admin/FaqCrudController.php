<?php

namespace App\Controller\Admin;

use App\Entity\Faq;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

class FaqCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return Faq::class;
    }

    public function configureFields(string $pageName): iterable
    {
        return [
            IdField::new('id')->hideOnForm(),

            TextField::new('title')
                ->setLabel('Question/Title')
                ->setHelp('The FAQ question or section header'),

            ChoiceField::new('category')
                ->setLabel('Category')
                ->setChoices([
                    'General' => 'general',
                    'Technical Specifications' => 'technical',
                    'Installation' => 'installation',
                    'Calibration' => 'calibration',
                    'Usage & Features' => 'usage',
                    'Troubleshooting' => 'troubleshooting',
                ]),

            TextareaField::new('content')
                ->setLabel('Answer/Content')
                ->setHelp('HTML is allowed. Use <ul><li> for lists, <strong> for bold, etc.')
                ->setFormTypeOption('attr', ['rows' => 15]),

            IntegerField::new('sortOrder')
                ->setLabel('Sort Order')
                ->setHelp('Lower numbers appear first (0, 10, 20, etc.)'),

            BooleanField::new('isActive')
                ->setLabel('Active')
                ->setHelp('Hide without deleting'),

            DateTimeField::new('createdAt')
                ->hideOnForm(),

            DateTimeField::new('updatedAt')
                ->hideOnForm(),
        ];
    }
}
