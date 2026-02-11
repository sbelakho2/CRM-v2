<?php

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\RepeatedType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Security\Core\Validator\Constraints as SecurityAssert;

class ChangePasswordType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('currentPassword', PasswordType::class, [
                'label' => 'auth.current_password',
                'mapped' => false,
                'attr' => [
                    'autocomplete' => 'current-password',
                    'class' => 'rams-form__input'
                ],
                'constraints' => [
                    new NotBlank([
                        'message' => 'validation.required',
                    ]),
                ],
            ])
            ->add('newPassword', RepeatedType::class, [
                'type' => PasswordType::class,
                'mapped' => false,
                'first_options' => [
                    'label' => 'auth.new_password',
                    'attr' => [
                        'autocomplete' => 'new-password',
                        'class' => 'rams-form__input'
                    ],
                    'constraints' => [
                        new NotBlank([
                            'message' => 'validation.required',
                        ]),
                        new Length([
                            'min' => 6,
                            'minMessage' => 'validation.min_length',
                            'max' => 4096,
                        ]),
                    ],
                ],
                'second_options' => [
                    'label' => 'auth.confirm_password',
                    'attr' => [
                        'autocomplete' => 'new-password',
                        'class' => 'rams-form__input'
                    ],
                ],
                'invalid_message' => 'validation.password_mismatch',
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'translation_domain' => 'messages',
        ]);
    }
}
