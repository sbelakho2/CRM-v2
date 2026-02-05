<?php

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\TelType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

class MeetingBookingType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, [
                'label' => 'Your Name',
                'constraints' => [
                    new Assert\NotBlank(['message' => 'Please enter your name']),
                    new Assert\Length(['max' => 255]),
                ],
                'attr' => [
                    'class' => 'rams-input',
                    'placeholder' => 'John Doe',
                ],
            ])
            ->add('email', EmailType::class, [
                'label' => 'Email Address',
                'constraints' => [
                    new Assert\NotBlank(['message' => 'Please enter your email']),
                    new Assert\Email(['message' => 'Please enter a valid email address']),
                ],
                'attr' => [
                    'class' => 'rams-input',
                    'placeholder' => 'john@company.com',
                ],
            ])
            ->add('phone', TelType::class, [
                'label' => 'Phone Number',
                'required' => false,
                'attr' => [
                    'class' => 'rams-input',
                    'placeholder' => '+1 (555) 123-4567',
                ],
            ])
            ->add('company', TextType::class, [
                'label' => 'Company',
                'required' => false,
                'attr' => [
                    'class' => 'rams-input',
                    'placeholder' => 'Your company name',
                ],
            ])
            ->add('notes', TextareaType::class, [
                'label' => 'Additional Notes',
                'required' => false,
                'attr' => [
                    'class' => 'rams-input',
                    'rows' => 4,
                    'placeholder' => 'Is there anything you\'d like us to know before the meeting?',
                ],
            ])
        ;
    }
    
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            // No data class - just a simple form
        ]);
    }
}
