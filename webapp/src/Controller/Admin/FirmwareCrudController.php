<?php

namespace App\Controller\Admin;

use App\Entity\Firmware;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Filter\BooleanFilter;
use EasyCorp\Bundle\EasyAdminBundle\Filter\TextFilter;
use EasyCorp\Bundle\EasyAdminBundle\Field\Field;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\String\Slugger\SluggerInterface;

class FirmwareCrudController extends AbstractCrudController
{
    public function __construct(
        private SluggerInterface $slugger,
        #[Autowire('%kernel.project_dir%')]
        private string $projectDir
    ) {}

    public static function getEntityFqcn(): string
    {
        return Firmware::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Firmware')
            ->setEntityLabelInPlural('Firmware Versions')
            ->setSearchFields(['version', 'deviceType', 'changelog'])
            ->setDefaultSort(['releasedAt' => 'DESC'])
            ->setPaginatorPageSize(20);
    }

    public function configureActions(Actions $actions): Actions
    {
        return $actions
            ->add(Crud::PAGE_INDEX, Action::DETAIL)
            ->reorder(Crud::PAGE_INDEX, [Action::DETAIL, Action::EDIT, Action::DELETE]);
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')
            ->hideOnForm();

        yield TextField::new('version', 'Version')
            ->setRequired(true)
            ->setHelp('Semantic version, e.g., 1.2.3');

        yield TextField::new('deviceType', 'Device Type')
            ->setHelp('Leave blank for all device types, or specify e.g., "ESP32-S3"');

        yield Field::new('firmwareFile', 'Firmware File (.bin)')
            ->setFormType(FileType::class)
            ->setFormTypeOptions([
                'mapped' => false,
                'required' => false,
                'attr' => ['accept' => '.bin'],
            ])
            ->setHelp('Upload .bin file from PlatformIO build')
            ->onlyOnForms();

        yield TextField::new('filename', 'Filename')
            ->hideOnForm();

        yield TextField::new('fileSizeFormatted', 'File Size')
            ->hideOnForm();

        yield TextField::new('checksum', 'Checksum (MD5)')
            ->hideOnForm()
            ->hideOnIndex();

        yield BooleanField::new('isStable', 'Stable Release')
            ->setHelp('Mark as stable to make available for auto-updates');

        yield BooleanField::new('isDeprecated', 'Deprecated')
            ->setHelp('Deprecated versions are hidden from download');

        yield TextField::new('minimumPreviousVersion', 'Min. Previous Version')
            ->hideOnIndex()
            ->setHelp('Minimum firmware version required to update to this version');

        yield TextareaField::new('changelog', 'Changelog')
            ->setNumOfRows(6)
            ->hideOnIndex();

        yield DateTimeField::new('releasedAt', 'Release Date')
            ->setHelp('When this version was released');

        yield IntegerField::new('downloadCount', 'Downloads')
            ->hideOnForm();

        yield DateTimeField::new('createdAt', 'Created')
            ->hideOnForm()
            ->hideOnIndex();

        yield DateTimeField::new('updatedAt', 'Updated')
            ->hideOnForm()
            ->hideOnIndex();
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters
            ->add(TextFilter::new('version'))
            ->add(TextFilter::new('deviceType'))
            ->add(BooleanFilter::new('isStable'))
            ->add(BooleanFilter::new('isDeprecated'));
    }

    public function persistEntity(EntityManagerInterface $em, $entityInstance): void
    {
        $this->handleFileUpload($entityInstance);
        parent::persistEntity($em, $entityInstance);
    }

    public function updateEntity(EntityManagerInterface $em, $entityInstance): void
    {
        $this->handleFileUpload($entityInstance);
        parent::updateEntity($em, $entityInstance);
    }

    public function deleteEntity(EntityManagerInterface $em, $entityInstance): void
    {
        // Delete the physical file before removing the database entry
        if ($entityInstance instanceof Firmware && $entityInstance->getFilename()) {
            $filePath = $this->projectDir . '/public/' . $entityInstance->getFilePath();
            if (file_exists($filePath)) {
                unlink($filePath);
            }
        }

        parent::deleteEntity($em, $entityInstance);
    }

    private function handleFileUpload(Firmware $firmware): void
    {
        $request = $this->getContext()->getRequest();
        $form = $request->files->get('Firmware');

        if (!$form || !isset($form['firmwareFile'])) {
            return;
        }

        /** @var UploadedFile|null $file */
        $file = $form['firmwareFile'];

        if (!$file) {
            return;
        }

        // Create firmware directory if it doesn't exist
        $uploadDir = $this->projectDir . '/public/' . Firmware::getStoragePath();
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }

        // Generate safe filename - always use .bin extension for firmware
        $newFilename = sprintf('firmware-v%s-%s.bin',
            $firmware->getVersion() ?? 'unknown',
            uniqid()
        );

        // Move file
        $file->move($uploadDir, $newFilename);
        $filePath = $uploadDir . '/' . $newFilename;

        // Update entity
        $firmware->setFilename($newFilename);
        $firmware->setFileSize(filesize($filePath));
        $firmware->setChecksum(md5_file($filePath));

        // Set release date if not set
        if (!$firmware->getReleasedAt()) {
            $firmware->setReleasedAt(new \DateTimeImmutable());
        }
    }
}
