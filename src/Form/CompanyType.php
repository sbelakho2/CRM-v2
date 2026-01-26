<?php

namespace App\Form;

use App\Entity\Company;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\UrlType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Url;

class CompanyType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, [
                'label' => 'company.company_name',
                'attr' => [
                    'class' => 'rams-form__input',
                    'placeholder' => 'company.form.name_placeholder'
                ],
                'constraints' => [
                    new NotBlank(['message' => 'validation.required'])
                ]
            ])
            ->add('sector', ChoiceType::class, [
                'label' => 'company.sector',
                'choices' => [
                    'company.sectors.automotive' => 'Automotive',
                    'company.sectors.industrial' => 'Industrial',
                    'company.sectors.aerospace' => 'Aerospace',
                    'company.sectors.rail' => 'Rail',
                    'company.sectors.renewables' => 'Renewables',
                    'company.sectors.power_electronics' => 'Power Electronics',
                ],
                'choice_translation_domain' => 'messages',
                'attr' => ['class' => 'rams-form__select'],
                'placeholder' => 'company.form.select_sector',
                'constraints' => [
                    new NotBlank(['message' => 'validation.required'])
                ]
            ])
            ->add('accountTier', ChoiceType::class, [
                'label' => 'company.account_tier',
                'choices' => [
                    'company.account_tiers.tier_a' => 'A',
                    'company.account_tiers.tier_b' => 'B',
                    'company.account_tiers.tier_c' => 'C',
                ],
                'choice_translation_domain' => 'messages',
                'attr' => ['class' => 'rams-form__select'],
                'placeholder' => 'company.form.select_tier'
            ])
            ->add('pipelineStage', ChoiceType::class, [
                'label' => 'company.pipeline_stage',
                'choices' => [
                    'company.pipeline_stages.prospect' => 'Prospect',
                    'company.pipeline_stages.mql' => 'MQL',
                    'company.pipeline_stages.sql' => 'SQL',
                    'company.pipeline_stages.sqo' => 'SQO',
                    'company.pipeline_stages.proposal' => 'Proposal',
                    'company.pipeline_stages.award' => 'Award',
                ],
                'choice_translation_domain' => 'messages',
                'attr' => ['class' => 'rams-form__select'],
                'placeholder' => 'company.form.select_stage'
            ])
            ->add('region', TextType::class, [
                'label' => 'company.region',
                'attr' => [
                    'class' => 'rams-form__input',
                    'placeholder' => 'company.form.region_placeholder'
                ],
                'required' => false
            ])
            ->add('website', UrlType::class, [
                'label' => 'company.website',
                'attr' => [
                    'class' => 'rams-form__input',
                    'placeholder' => 'company.form.website_placeholder'
                ],
                'required' => false,
                'constraints' => [
                    new Url(['message' => 'validation.url'])
                ]
            ])
            ->add('googleDriveLink', UrlType::class, [
                'label' => 'company.google_drive_link',
                'attr' => [
                    'class' => 'rams-form__input',
                    'placeholder' => 'company.form.google_drive_placeholder'
                ],
                'required' => false,
                'constraints' => [
                    new Url(['message' => 'validation.url'])
                ]
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Company::class,
            'translation_domain' => 'messages',
        ]);
    }
}
