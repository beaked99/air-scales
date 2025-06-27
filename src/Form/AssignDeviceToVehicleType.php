<?php
// src/Form/AssignDeviceToVehicleType.php

namespace App\Form;

use App\Entity\Vehicle;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use App\Entity\AxleGroup;

class AssignDeviceToVehicleType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('year', IntegerType::class, [
                'constraints' => [new NotBlank()],
            ])
            ->add('make', TextType::class, [
                'constraints' => [new NotBlank()],
            ])
            ->add('model', TextType::class, [
                'constraints' => [new NotBlank()],
            ])
            ->add('vin', TextType::class, [
                'constraints' => [new NotBlank(), new Length(['min' => 3, 'max' => 20])],
            ])
            ->add('license_plate', TextType::class, [
                'required' => false,
            ])
            ->add('nickname', TextType::class, [
                'required' => false,
            ])
            ->add('axleGroup', EntityType::class, [
                'class' => AxleGroup::class,
                'choice_label' => 'label',
                'placeholder' => 'Select Axle Group',
                'required' => false,
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Vehicle::class,
        ]);
    }
}
