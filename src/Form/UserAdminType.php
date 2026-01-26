<?php

namespace App\Form;

use App\Entity\User;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;

class UserAdminType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $isNew = $options['is_new'] ?? false;
        
        $builder
            ->add('email', EmailType::class, [
                'label' => 'admin.users.email',
            ])
            ->add('firstName', TextType::class, [
                'label' => 'admin.users.first_name',
            ])
            ->add('lastName', TextType::class, [
                'label' => 'admin.users.last_name',
            ])
            ->add('active', CheckboxType::class, [
                'label' => 'admin.users.active',
                'required' => false,
            ])
            ->add('roles', ChoiceType::class, [
                'choices' => [
                    'admin.users.roles.admin' => 'ROLE_ADMIN',
                    'admin.users.roles.sales_ops' => 'ROLE_SALES_OPS',
                    'admin.users.roles.digital_rep' => 'ROLE_DIGITAL_REP',
                    'admin.users.roles.field_rep' => 'ROLE_FIELD_REP',
                    'admin.users.roles.user' => 'ROLE_USER',
                ],
                'choice_translation_domain' => 'messages',
                'multiple' => true,
                'expanded' => true,
            ]);
            
        // Add password field only for new users
        if ($isNew) {
            $builder->add('plainPassword', PasswordType::class, [
                'mapped' => false,
                'label' => 'admin.users.password',
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
            ]);
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => User::class,
            'is_new' => false,
            'translation_domain' => 'messages',
        ]);
    }
}
