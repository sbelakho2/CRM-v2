<?php

namespace App\Form;

use App\Entity\User;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Email;
use Symfony\Component\Validator\Constraints\NotBlank;

class ProfileAccountType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('firstName', TextType::class, [
                'label' => 'user.first_name',
                'attr' => ['class' => 'rams-form__input'],
                'constraints' => [new NotBlank(['message' => 'validation.required'])],
            ])
            ->add('lastName', TextType::class, [
                'label' => 'user.last_name',
                'attr' => ['class' => 'rams-form__input'],
                'constraints' => [new NotBlank(['message' => 'validation.required'])],
            ])
            ->add('email', EmailType::class, [
                'label' => 'common.email',
                'attr' => ['class' => 'rams-form__input'],
                'constraints' => [
                    new NotBlank(['message' => 'validation.required']),
                    new Email(['message' => 'validation.email']),
                ],
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
