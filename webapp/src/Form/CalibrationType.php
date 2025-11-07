<?php
namespace App\Form;

use App\Entity\Calibration;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\OptionsResolver\OptionsResolver;

class CalibrationType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('scaleWeight', NumberType::class)
            ->add('airPressure', NumberType::class)
            ->add('ambientAirPressure', NumberType::class)
            ->add('airTemperature', NumberType::class)
            ->add('elevation', NumberType::class)
            ->add('comment', TextareaType::class, [
                'required' => false
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Calibration::class,
        ]);
    }
}