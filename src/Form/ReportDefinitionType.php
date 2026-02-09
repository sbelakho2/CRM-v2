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
                'label' => 'report.form.name',
                'attr' => [
                    'class' => 'rams-input',
                    'placeholder' => 'report.form.name_placeholder',
                ],
            ])
            ->add('description', TextareaType::class, [
                'label' => 'report.form.description',
                'required' => false,
                'attr' => [
                    'class' => 'rams-input',
                    'rows' => 3,
                    'placeholder' => 'report.form.description_placeholder',
                ],
            ])
            ->add('dataSource', ChoiceType::class, [
                'label' => 'report.form.data_source',
                'choices' => ReportDefinition::getDataSources(),
                'choice_translation_domain' => 'messages',
                'attr' => ['class' => 'rams-select'],
                'placeholder' => 'report.form.select_data_source',
            ])
            ->add('reportType', ChoiceType::class, [
                'label' => 'report.form.report_type',
                'choices' => ReportDefinition::getReportTypes(),
                'choice_translation_domain' => 'messages',
                'attr' => ['class' => 'rams-select'],
            ])
            ->add('category', TextType::class, [
                'label' => 'report.form.category',
                'required' => false,
                'attr' => [
                    'class' => 'rams-input',
                    'placeholder' => 'report.form.category_placeholder',
                ],
            ])
            ->add('recordLimit', IntegerType::class, [
                'label' => 'report.form.record_limit',
                'required' => false,
                'attr' => [
                    'class' => 'rams-input',
                    'placeholder' => 'report.form.record_limit_placeholder',
                    'min' => 1,
                    'max' => 10000,
                ],
            ])
            ->add('isPublic', CheckboxType::class, [
                'label' => 'report.form.is_public',
                'required' => false,
                'attr' => ['class' => 'rams-checkbox'],
            ])
            ->add('isFavorite', CheckboxType::class, [
                'label' => 'report.form.is_favorite',
                'required' => false,
                'attr' => ['class' => 'rams-checkbox'],
            ])
        ;
    }
    
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => ReportDefinition::class,
            'translation_domain' => 'messages',
        ]);
    }
}
