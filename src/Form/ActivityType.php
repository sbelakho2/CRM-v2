<?php

namespace App\Form;

use App\Entity\Activity;
use App\Entity\Company;
use App\Entity\Contact;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\NotBlank;

class ActivityType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('type', ChoiceType::class, [
                'label' => 'Activity Type',
                'choices' => [
                    'Call' => 'Call',
                    'Email' => 'Email',
                    'Meeting' => 'Meeting',
                    'LinkedIn Message' => 'LinkedIn Message',
                    'LinkedIn InMail' => 'LinkedIn InMail',
                    'LinkedIn Connection Request' => 'LinkedIn Connection Request',
                    'Follow-up' => 'Follow-up',
                ],
                    'attr' => ['class' => 'rams-form__select'],
                'placeholder' => 'Select type',
                'constraints' => [
                    new NotBlank(['message' => 'Activity type is required'])
                ]
            ])
            ->add('company', EntityType::class, [
                'class' => Company::class,
                'choice_label' => 'name',
                'label' => 'Company',
                'attr' => ['class' => 'rams-form__select'],
                'placeholder' => 'Select company',
                'constraints' => [
                    new NotBlank(['message' => 'Company is required'])
                ]
            ])
            ->add('contact', EntityType::class, [
                'class' => Contact::class,
                'choice_label' => function (Contact $contact) {
                    return $contact->getFirstName() . ' ' . $contact->getLastName();
                },
                'label' => 'Contact',
                'attr' => ['class' => 'rams-form__select'],
                'placeholder' => 'Select contact (optional)',
                'required' => false
            ])
            ->add('activityDate', DateType::class, [
                'label' => 'Activity Date',
                'widget' => 'single_text',
                    'attr' => ['class' => 'rams-form__input'],
                'constraints' => [
                    new NotBlank(['message' => 'Activity date is required'])
                ]
            ])
            ->add('notes', TextareaType::class, [
                'label' => 'Notes',
                'attr' => [
                        'class' => 'rams-form__textarea',
                    'rows' => 4,
                    'placeholder' => 'Enter activity details, outcomes, next steps...'
                ],
                'required' => false
            ])
            ->add('outcome', ChoiceType::class, [
                'label' => 'Outcome',
                'choices' => [
                    'Successful' => 'Successful',
                    'Follow-up Required' => 'Follow-up Required',
                    'No Answer' => 'No Answer',
                    'Not Interested' => 'Not Interested',
                    'Pending' => 'Pending',
                ],
                    'attr' => ['class' => 'rams-form__select'],
                'placeholder' => 'Select outcome',
                'required' => false
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Activity::class,
        ]);
    }
}
