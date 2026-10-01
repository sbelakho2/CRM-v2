<?php

namespace App\Form;

use App\Entity\Company;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\CountryType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\UrlType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Url;

class CompanyType extends AbstractType
{
    public const REGION_CHOICES = [
        'Morocco' => 'MA',
        'United States' => 'US',
        'Europe' => 'EU',
        'United Kingdom' => 'GB',
        'Egypt' => 'EG',
        'GCC / Gulf' => 'GCC',
    ];
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
                    new NotBlank(['message' => 'validation.required']),
                    new Length(['max' => 255]),
                ]
            ])
            ->add('legalName', TextType::class, [
                'label' => 'Legal Name',
                'attr' => [
                    'class' => 'rams-form__input',
                    'placeholder' => 'Full legal entity name (optional)'
                ],
                'required' => false
            ])
            ->add('sector', ChoiceType::class, [
                'label' => 'company.sector',
                'choices' => [
                    'company.sectors.automotive' => 'Automotive',
                    'company.sectors.aerospace' => 'Aerospace',
                    'company.sectors.industrial' => 'Industrial',
                    'company.sectors.rail' => 'Rail',
                    'company.sectors.renewables' => 'Renewables',
                    'company.sectors.medical' => 'Medical',
                    'company.sectors.defense' => 'Defense',
                    'company.sectors.telecom' => 'Telecom',
                    'company.sectors.hvac' => 'HVAC',
                    'company.sectors.marine' => 'Marine',
                    'company.sectors.power_electronics' => 'Power Electronics',
                    'company.sectors.consumer_electronics' => 'Consumer Electronics',
                    'company.sectors.data_center' => 'Data Center',
                    'company.sectors.energy_storage' => 'Energy Storage',
                    'company.sectors.other' => 'Other',
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
            ->add('region', ChoiceType::class, [
                'label' => 'company.region',
                'choices' => self::REGION_CHOICES,
                'attr' => ['class' => 'rams-form__select'],
                'placeholder' => 'Select region (optional)',
                'required' => false
            ])
            ->add('country', CountryType::class, [
                'label' => 'Country',
                'required' => false,
                'attr' => ['class' => 'rams-form__select'],
            ])
            ->add('city', TextType::class, [
                'label' => 'City',
                'attr' => [
                    'class' => 'rams-form__input',
                    'placeholder' => 'City name'
                ],
                'required' => false
            ])
            ->add('address', TextType::class, [
                'label' => 'Address',
                'attr' => [
                    'class' => 'rams-form__input',
                    'placeholder' => 'Street address'
                ],
                'required' => false
            ])
            ->add('physicalSite', TextType::class, [
                'label' => 'Physical Site / Location',
                'attr' => [
                    'class' => 'rams-form__input',
                    'placeholder' => 'e.g. Tanger Free Zone, Detroit Plant'
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
                    new Url(['message' => 'validation.url']),
                    new Length(['max' => 2048]),
                ]
            ])
            ->add('linkedinCompanyUrl', UrlType::class, [
                'label' => 'LinkedIn Company URL',
                'attr' => [
                    'class' => 'rams-form__input',
                    'placeholder' => 'https://linkedin.com/company/...'
                ],
                'required' => false,
                'constraints' => [
                    new Url(['message' => 'validation.url']),
                    new Length(['max' => 500]),
                ]
            ])
            // @deprecated: linkedInUrl is a duplicate of linkedinCompanyUrl for Company entities.
            // Kept for backward compatibility with existing data. Prefer linkedinCompanyUrl for company pages.
            ->add('linkedInUrl', UrlType::class, [
                'label' => 'LinkedIn Profile URL',
                'attr' => [
                    'class' => 'rams-form__input',
                    'placeholder' => 'https://linkedin.com/in/...',
                ],
                'required' => false,
                'constraints' => [
                    new Url(['message' => 'validation.url']),
                    new Length(['max' => 2048]),
                ],
            ])
            ->add('googleDriveLink', UrlType::class, [
                'label' => 'company.google_drive_link',
                'attr' => [
                    'class' => 'rams-form__input',
                    'placeholder' => 'company.form.google_drive_placeholder'
                ],
                'required' => false,
                'constraints' => [
                    new Url(['message' => 'validation.url']),
                    new Length(['max' => 500]),
                ]
            ])
            ->add('notes', TextareaType::class, [
                'label' => 'Notes & Description',
                'attr' => [
                    'class' => 'rams-form__input',
                    'placeholder' => 'Company description, notes, key contacts...',
                    'rows' => 4
                ],
                'required' => false
            ])
            ->add('sourceNotes', TextareaType::class, [
                'label' => 'Source Notes',
                'attr' => [
                    'class' => 'rams-form__input',
                    'placeholder' => 'How was this company discovered?',
                    'rows' => 2
                ],
                'required' => false
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
