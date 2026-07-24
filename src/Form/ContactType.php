<?php

namespace App\Form;

use App\Entity\Company;
use App\Entity\Contact;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\TelType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\UrlType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Email;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Url;

class ContactType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('firstName', TextType::class, [
                'label' => 'contact.first_name',
                'attr' => [
                    'class' => 'rams-form__input',
                    'placeholder' => 'contact.form.first_name_placeholder',
                ],
                'constraints' => [
                    new NotBlank(['message' => 'validation.required']),
                    new Length(['max' => 100]),
                ],
            ])
            ->add('lastName', TextType::class, [
                'label' => 'contact.last_name',
                'attr' => [
                    'class' => 'rams-form__input',
                    'placeholder' => 'contact.form.last_name_placeholder',
                ],
                'constraints' => [
                    new NotBlank(['message' => 'validation.required']),
                    new Length(['max' => 100]),
                ],
            ])
            ->add('company', EntityType::class, [
                'class' => Company::class,
                'choice_label' => 'name',
                'label' => 'company.title_singular',
                'attr' => ['class' => 'rams-form__select'],
                'placeholder' => 'contact.form.select_company',
                'constraints' => [
                    new NotBlank(['message' => 'validation.required']),
                ],
            ])
            ->add('jobTitle', TextType::class, [
                'label' => 'contact.job_title',
                'attr' => [
                    'class' => 'rams-form__input',
                    'placeholder' => 'contact.form.job_title_placeholder',
                ],
                'required' => false,
            ])
            ->add('role', ChoiceType::class, [
                'label' => 'contact.role',
                'choices' => [
                    'contact.roles.ceo' => 'CEO',
                    'contact.roles.procurement_manager' => 'Procurement Manager',
                    'contact.roles.engineering_manager' => 'Engineering Manager',
                    'contact.roles.quality_manager' => 'Quality Manager',
                    'contact.roles.operations_manager' => 'Operations Manager',
                    'contact.roles.finance_manager' => 'Finance Manager',
                    'contact.roles.other' => 'Other',
                ],
                'choice_translation_domain' => 'messages',
                'attr' => ['class' => 'rams-form__select'],
                'placeholder' => 'contact.form.select_role',
                'required' => false,
            ])
            ->add('email', EmailType::class, [
                'label' => 'common.email',
                'attr' => [
                    'class' => 'rams-form__input',
                    'placeholder' => 'contact.form.email_placeholder',
                ],
                'required' => false,
                'constraints' => [
                    new Email(['message' => 'validation.email']),
                ],
            ])
            ->add('phone', TelType::class, [
                'label' => 'common.phone',
                'attr' => [
                    'class' => 'rams-form__input',
                    'placeholder' => 'contact.form.phone_placeholder',
                ],
                'required' => false,
            ])
            ->add('linkedInUrl', UrlType::class, [
                'label' => 'LinkedIn URL',
                'attr' => [
                    'class' => 'rams-form__input',
                    'placeholder' => 'https://www.linkedin.com/in/...',
                ],
                'required' => false,
                'constraints' => [
                    new Url(['message' => 'validation.url']),
                ],
            ])
            ->add('source', ChoiceType::class, [
                'label' => 'Source',
                'choices' => [
                    'LinkedIn' => 'LinkedIn',
                    'WebCrawler Enrichment' => 'WebCrawler Enrichment',
                    'Portal' => 'Portal',
                    'Referral' => 'Referral',
                    'Cold Outreach' => 'Cold Outreach',
                    'Trade Show' => 'Trade Show',
                    'Manual' => 'Manual',
                ],
                'attr' => ['class' => 'rams-form__select'],
                'placeholder' => 'Select source...',
                'required' => false,
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Contact::class,
            'translation_domain' => 'messages',
        ]);
    }
}
