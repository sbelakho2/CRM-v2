<?php

namespace App\Form;

use App\Entity\ReportDefinition;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class ReportDefinitionType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, [
                'label' => 'Report Name',
                'attr' => [
                    'class' => 'rams-input',
                    'placeholder' => 'e.g., Monthly Sales Report',
                ],
            ])
            ->add('description', TextareaType::class, [
                'label' => 'Description',
                'required' => false,
                'attr' => [
                    'class' => 'rams-input',
                    'rows' => 3,
                    'placeholder' => 'Brief description of what this report shows...',
                ],
            ])
            ->add('dataSource', ChoiceType::class, [
                'label' => 'Data Source',
                'choices' => ReportDefinition::getDataSources(),
                'attr' => ['class' => 'rams-select'],
                'placeholder' => 'Select data source...',
            ])
            ->add('reportType', ChoiceType::class, [
                'label' => 'Report Type',
                'choices' => ReportDefinition::getReportTypes(),
                'attr' => ['class' => 'rams-select'],
            ])
            ->add('category', TextType::class, [
                'label' => 'Category',
                'required' => false,
                'attr' => [
                    'class' => 'rams-input',
                    'placeholder' => 'e.g., Sales, Marketing, Operations',
                ],
            ])
            ->add('recordLimit', IntegerType::class, [
                'label' => 'Record Limit',
                'required' => false,
                'attr' => [
                    'class' => 'rams-input',
                    'placeholder' => 'Leave empty for no limit',
                    'min' => 1,
                    'max' => 10000,
                ],
            ])
            ->add('isPublic', CheckboxType::class, [
                'label' => 'Make report public (visible to all users)',
                'required' => false,
                'attr' => ['class' => 'rams-checkbox'],
            ])
            ->add('isFavorite', CheckboxType::class, [
                'label' => 'Add to favorites',
                'required' => false,
                'attr' => ['class' => 'rams-checkbox'],
            ])
        ;
    }
    
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => ReportDefinition::class,
        ]);
    }
}
