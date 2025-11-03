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
                'label' => 'Company Name',
                'attr' => [
                    'class' => 'form-input',
                    'placeholder' => 'Enter company name'
                ],
                'constraints' => [
                    new NotBlank(['message' => 'Company name is required'])
                ]
            ])
            ->add('sector', ChoiceType::class, [
                'label' => 'Sector',
                'choices' => [
                    'Automotive' => 'Automotive',
                    'Industrial' => 'Industrial',
                    'Aerospace' => 'Aerospace',
                    'Rail' => 'Rail',
                    'Renewables' => 'Renewables',
                    'Power Electronics' => 'Power Electronics',
                ],
                'attr' => ['class' => 'form-select'],
                'placeholder' => 'Select sector',
                'constraints' => [
                    new NotBlank(['message' => 'Sector is required'])
                ]
            ])
            ->add('accountTier', ChoiceType::class, [
                'label' => 'Account Tier',
                'choices' => [
                    'Tier A' => 'A',
                    'Tier B' => 'B',
                    'Tier C' => 'C',
                ],
                'attr' => ['class' => 'form-select'],
                'placeholder' => 'Select tier'
            ])
            ->add('pipelineStage', ChoiceType::class, [
                'label' => 'Pipeline Stage',
                'choices' => [
                    'Prospect' => 'Prospect',
                    'MQL (Marketing Qualified Lead)' => 'MQL',
                    'SQL (Sales Qualified Lead)' => 'SQL',
                    'SQO (Sales Qualified Opportunity)' => 'SQO',
                    'Proposal' => 'Proposal',
                    'Award' => 'Award',
                ],
                'attr' => ['class' => 'form-select'],
                'placeholder' => 'Select stage'
            ])
            ->add('region', TextType::class, [
                'label' => 'Region',
                'attr' => [
                    'class' => 'form-input',
                    'placeholder' => 'e.g., Morocco - TAC, EU - Germany'
                ],
                'required' => false
            ])
            ->add('website', UrlType::class, [
                'label' => 'Website',
                'attr' => [
                    'class' => 'form-input',
                    'placeholder' => 'https://example.com'
                ],
                'required' => false,
                'constraints' => [
                    new Url(['message' => 'Please enter a valid URL'])
                ]
            ])
            ->add('linkedInUrl', UrlType::class, [
                'label' => 'LinkedIn URL',
                'attr' => [
                    'class' => 'form-input',
                    'placeholder' => 'https://linkedin.com/company/example'
                ],
                'required' => false,
                'constraints' => [
                    new Url(['message' => 'Please enter a valid LinkedIn URL'])
                ]
            ])
            ->add('googleDriveLink', UrlType::class, [
                'label' => 'Google Drive Link',
                'attr' => [
                    'class' => 'form-input',
                    'placeholder' => 'https://drive.google.com/...'
                ],
                'required' => false,
                'constraints' => [
                    new Url(['message' => 'Please enter a valid Google Drive URL'])
                ]
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Company::class,
        ]);
    }
}
