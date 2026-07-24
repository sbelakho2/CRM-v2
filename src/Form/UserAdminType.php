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
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;

class UserAdminType extends AbstractType
{
    private AuthorizationCheckerInterface $authChecker;

    public function __construct(AuthorizationCheckerInterface $authChecker)
    {
        $this->authChecker = $authChecker;
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $isNew = $options['is_new'] ?? false;
        
        $builder
            ->add('email', EmailType::class, [
                'label' => 'administration.users.email',
            ])
            ->add('firstName', TextType::class, [
                'label' => 'administration.users.first_name',
            ])
            ->add('lastName', TextType::class, [
                'label' => 'administration.users.last_name',
            ])
            ->add('active', CheckboxType::class, [
                'label' => 'administration.users.active',
                'required' => false,
            ])
            ->add('roles', ChoiceType::class, [
                'choices' => [
                    'administration.users.roles.admin' => 'ROLE_ADMIN',
                    'administration.users.roles.engineering' => 'ROLE_ENGINEERING',
                    'administration.users.roles.sales_ops' => 'ROLE_SALES_OPS',
                    'administration.users.roles.digital_rep' => 'ROLE_DIGITAL_REP',
                    'administration.users.roles.field_rep' => 'ROLE_FIELD_REP',
                    'administration.users.roles.manager' => 'ROLE_MANAGER',
                    'administration.users.roles.sales' => 'ROLE_SALES',
                    'administration.users.roles.user' => 'ROLE_USER',
                    'administration.users.roles.viewer' => 'ROLE_VIEWER',
                ],
                'choice_translation_domain' => 'messages',
                'multiple' => true,
                'expanded' => false,
                'attr' => ['class' => 'rams-form__select', 'size' => 5],
            ]);
            
        $builder->addEventListener(FormEvents::SUBMIT, function (FormEvent $event) {
            $user = $event->getData();
            $roles = $user->getRoles();

            $manageableRoles = [
                'ROLE_USER',
                'ROLE_VIEWER',
                'ROLE_SALES',
                'ROLE_DIGITAL_REP',
                'ROLE_FIELD_REP',
                'ROLE_SALES_OPS',
                'ROLE_MANAGER',
                'ROLE_ENGINEERING',
            ];

            if ($this->authChecker->isGranted('ROLE_ADMIN')) {
                $manageableRoles[] = 'ROLE_ADMIN';
            }

            foreach ($roles as $role) {
                if (!in_array($role, $manageableRoles, true)) {
                    $user->setRoles(array_intersect($roles, $manageableRoles));
                    break;
                }
            }
        });
        if ($isNew) {
            $builder->add('plainPassword', PasswordType::class, [
                'mapped' => false,
                'label' => 'administration.users.password',
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
