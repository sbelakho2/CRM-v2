<?php

namespace App\Form;

use App\Entity\Contact;
use App\Entity\Company;
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
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Url;

class ContactType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('firstName', TextType::class, [
                'label' => 'First Name',
                'attr' => [
                    'class' => 'form-input',
                    'placeholder' => 'Enter first name'
                ],
                'constraints' => [
                    new NotBlank(['message' => 'First name is required'])
                ]
            ])
            ->add('lastName', TextType::class, [
                'label' => 'Last Name',
                'attr' => [
                    'class' => 'form-input',
                    'placeholder' => 'Enter last name'
                ],
                'constraints' => [
                    new NotBlank(['message' => 'Last name is required'])
                ]
            ])
            ->add('company', EntityType::class, [
                'class' => Company::class,
                'choice_label' => 'name',
                'label' => 'Company',
                'attr' => ['class' => 'form-select'],
                'placeholder' => 'Select company',
                'constraints' => [
                    new NotBlank(['message' => 'Company is required'])
                ]
            ])
            ->add('jobTitle', TextType::class, [
                'label' => 'Job Title',
                'attr' => [
                    'class' => 'form-input',
                    'placeholder' => 'e.g., Procurement Manager'
                ],
                'required' => false
            ])
            ->add('role', ChoiceType::class, [
                'label' => 'Role',
                'choices' => [
                    'CEO' => 'CEO',
                    'Procurement Manager' => 'Procurement Manager',
                    'Engineering Manager' => 'Engineering Manager',
                    'Quality Manager' => 'Quality Manager',
                    'Operations Manager' => 'Operations Manager',
                    'Supply Chain Manager' => 'Supply Chain Manager',
                ],
                'attr' => ['class' => 'form-select'],
                'placeholder' => 'Select role',
                'required' => false
            ])
            ->add('email', EmailType::class, [
                'label' => 'Email',
                'attr' => [
                    'class' => 'form-input',
                    'placeholder' => 'contact@company.com'
                ],
                'required' => false,
                'constraints' => [
                    new Email(['message' => 'Please enter a valid email'])
                ]
            ])
            ->add('phone', TelType::class, [
                'label' => 'Phone',
                'attr' => [
                    'class' => 'form-input',
                    'placeholder' => '+212 XXX XXX XXX'
                ],
                'required' => false
            ])
            ->add('linkedInUrl', UrlType::class, [
                'label' => 'LinkedIn URL',
                'attr' => [
                    'class' => 'form-input',
                    'placeholder' => 'https://linkedin.com/in/username'
                ],
                'required' => false,
                'constraints' => [
                    new Url(['message' => 'Please enter a valid LinkedIn URL'])
                ]
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Contact::class,
        ]);
    }
}
