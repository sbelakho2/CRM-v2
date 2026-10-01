<?php

namespace App\Form;

use App\Entity\User;
use App\Validator\PasswordPolicy;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\RepeatedType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

class RegistrationFormType extends AbstractType
{
    /**
     * @param array<string|int, mixed> $options
     */
    public /**
 * @param array<string|int, mixed> $options
 */
function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('email', EmailType::class, [
                'label' => 'common.email',
            ])
            ->add('firstName', TextType::class, [
                'label' => 'auth.first_name',
            ])
            ->add('lastName', TextType::class, [
                'label' => 'auth.last_name',
            ])
            ->add('plainPassword', RepeatedType::class, [
                'type' => PasswordType::class,
                'mapped' => false,
                'first_options' => ['label' => 'auth.password'],
                'second_options' => ['label' => 'auth.confirm_password'],
                'constraints' => [
                    new PasswordPolicy(),
                ],
            ])
            ->add('agreeTerms', CheckboxType::class, [
                'mapped' => false,
                'constraints' => [
                    new Assert\IsTrue(['message' => 'You must agree to the terms.']),
                ],
                'label' => 'I agree to the terms and privacy policy',
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => User::class,
            'translation_domain' => 'messages',
        ]);
    }
}
