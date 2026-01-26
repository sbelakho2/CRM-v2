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
                'label' => 'activity.type',
                'choices' => [
                    'activity.types.call' => 'Call',
                    'activity.types.email' => 'Email',
                    'activity.types.meeting' => 'Meeting',
                    'activity.types.follow_up' => 'Follow-up',
                    'activity.types.site_visit' => 'Site Visit',
                    'activity.types.demo' => 'Demo',
                ],
                'choice_translation_domain' => 'messages',
                'attr' => ['class' => 'rams-form__select'],
                'placeholder' => 'activity.form.select_type',
                'constraints' => [
                    new NotBlank(['message' => 'validation.required'])
                ]
            ])
            ->add('company', EntityType::class, [
                'class' => Company::class,
                'choice_label' => 'name',
                'label' => 'company.title_singular',
                'attr' => ['class' => 'rams-form__select'],
                'placeholder' => 'activity.form.select_company',
                'constraints' => [
                    new NotBlank(['message' => 'validation.required'])
                ]
            ])
            ->add('contact', EntityType::class, [
                'class' => Contact::class,
                'choice_label' => function (Contact $contact) {
                    return $contact->getFirstName() . ' ' . $contact->getLastName();
                },
                'label' => 'contact.title_singular',
                'attr' => ['class' => 'rams-form__select'],
                'placeholder' => 'activity.form.select_contact_optional',
                'required' => false
            ])
            ->add('activityDate', DateType::class, [
                'label' => 'activity.activity_date',
                'widget' => 'single_text',
                'attr' => ['class' => 'rams-form__input'],
                'constraints' => [
                    new NotBlank(['message' => 'validation.required'])
                ]
            ])
            ->add('notes', TextareaType::class, [
                'label' => 'common.notes',
                'attr' => [
                    'class' => 'rams-form__textarea',
                    'rows' => 4,
                    'placeholder' => 'activity.form.notes_placeholder'
                ],
                'required' => false
            ])
            ->add('outcome', ChoiceType::class, [
                'label' => 'activity.outcome',
                'choices' => [
                    'activity.outcomes.successful' => 'Successful',
                    'activity.outcomes.follow_up_required' => 'Follow-up Required',
                    'activity.outcomes.no_answer' => 'No Answer',
                    'activity.outcomes.not_interested' => 'Not Interested',
                    'activity.outcomes.pending' => 'Pending',
                ],
                'choice_translation_domain' => 'messages',
                'attr' => ['class' => 'rams-form__select'],
                'placeholder' => 'activity.form.select_outcome',
                'required' => false
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Activity::class,
            'translation_domain' => 'messages',
        ]);
    }
}
