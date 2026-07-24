<?php

namespace App\Form;

use App\Entity\CalendarEvent;
use App\Entity\Company;
use App\Entity\Contact;
use App\Entity\Lead;
use App\Entity\RFQ;
use App\Entity\User;
use Doctrine\ORM\EntityRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\ColorType;
use Symfony\Component\Form\Extension\Core\Type\DateTimeType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\UrlType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class CalendarEventType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('title', TextType::class, [
                'label' => 'Event Title',
                'constraints' => [
                    new Assert\NotBlank(['message' => 'Please enter an event title']),
                    new Assert\Length(['max' => 255]),
                ],
                'attr' => [
                    'class' => 'rams-input',
                    'placeholder' => 'e.g., Sales Meeting with Acme Corp',
                ],
            ])
            ->add('description', TextareaType::class, [
                'label' => 'Description',
                'required' => false,
                'attr' => [
                    'class' => 'rams-input',
                    'rows' => 4,
                    'placeholder' => 'Meeting agenda, notes, or additional details...',
                ],
            ])
            ->add('eventType', ChoiceType::class, [
                'label' => 'Event Type',
                'choices' => CalendarEvent::getTypes(),
                'attr' => ['class' => 'rams-select'],
            ])
            ->add('startAt', DateTimeType::class, [
                'label' => 'Start',
                'widget' => 'single_text',
                'attr' => ['class' => 'rams-input'],
            ])
            ->add('endAt', DateTimeType::class, [
                'label' => 'End',
                'widget' => 'single_text',
                'attr' => ['class' => 'rams-input'],
            ])
            ->add('allDay', CheckboxType::class, [
                'label' => 'All Day Event',
                'required' => false,
                'attr' => ['class' => 'rams-checkbox'],
            ])
            ->add('location', TextType::class, [
                'label' => 'Location',
                'required' => false,
                'attr' => [
                    'class' => 'rams-input',
                    'placeholder' => 'Conference Room A, Client Office, etc.',
                ],
            ])
            ->add('meetingUrl', UrlType::class, [
                'label' => 'Meeting URL',
                'required' => false,
                'attr' => [
                    'class' => 'rams-input',
                    'placeholder' => 'https://zoom.us/j/... or https://meet.google.com/...',
                ],
            ])
            ->add('visibility', ChoiceType::class, [
                'label' => 'Visibility',
                'choices' => CalendarEvent::getVisibilities(),
                'attr' => ['class' => 'rams-select'],
            ])
            ->add('status', ChoiceType::class, [
                'label' => 'Status',
                'choices' => CalendarEvent::getStatuses(),
                'attr' => ['class' => 'rams-select'],
            ])
            ->add('color', ColorType::class, [
                'label' => 'Custom Color',
                'required' => false,
                'attr' => ['class' => 'rams-input'],
            ])
            ->add('reminderMinutes', ChoiceType::class, [
                'label' => 'Reminder',
                'required' => false,
                'placeholder' => 'No reminder',
                'choices' => [
                    '5 minutes before' => 5,
                    '10 minutes before' => 10,
                    '15 minutes before' => 15,
                    '30 minutes before' => 30,
                    '1 hour before' => 60,
                    '2 hours before' => 120,
                    '1 day before' => 1440,
                ],
                'attr' => ['class' => 'rams-select'],
            ])
            ->add('isRecurring', CheckboxType::class, [
                'label' => 'Recurring Event',
                'required' => false,
                'attr' => ['class' => 'rams-checkbox'],
            ])
            ->add('recurringFrequency', ChoiceType::class, [
                'label' => 'Repeat',
                'required' => false,
                'placeholder' => 'Select frequency',
                'choices' => CalendarEvent::getRecurrenceOptions(),
                'attr' => ['class' => 'rams-select'],
            ])
            ->add('recurringUntil', DateType::class, [
                'label' => 'Repeat Until',
                'required' => false,
                'widget' => 'single_text',
                'attr' => ['class' => 'rams-input'],
            ])
            ->add('recurringCount', IntegerType::class, [
                'label' => 'Number of Occurrences',
                'required' => false,
                'attr' => [
                    'class' => 'rams-input',
                    'min' => 1,
                    'max' => 365,
                    'placeholder' => 'e.g., 10',
                ],
            ])
            ->add('attendees', EntityType::class, [
                'class' => User::class,
                'label' => 'Attendees',
                'required' => false,
                'multiple' => true,
                'expanded' => false,
                'choice_label' => 'fullName',
                'query_builder' => function (EntityRepository $er) {
                    return $er->createQueryBuilder('u')
                        ->where('u.active = :active')
                        ->setParameter('active', true)
                        ->orderBy('u.firstName', 'ASC')
                        ->setMaxResults(500);
                },
                'attr' => [
                    'class' => 'rams-select',
                    'data-controller' => 'select2',
                ],
            ])
            ->add('company', EntityType::class, [
                'class' => Company::class,
                'label' => 'Related Company',
                'required' => false,
                'placeholder' => 'Select company...',
                'choice_label' => 'name',
                'query_builder' => function (EntityRepository $er) {
                    return $er->createQueryBuilder('c')
                        ->orderBy('c.name', 'ASC');
                },
                'attr' => [
                    'class' => 'rams-select',
                    'data-controller' => 'select2',
                ],
            ])
            ->add('contact', EntityType::class, [
                'class' => Contact::class,
                'label' => 'Related Contact',
                'required' => false,
                'placeholder' => 'Select contact...',
                'choice_label' => 'fullName',
                'query_builder' => function (EntityRepository $er) {
                    return $er->createQueryBuilder('c')
                        ->orderBy('c.lastName', 'ASC');
                },
                'attr' => [
                    'class' => 'rams-select',
                    'data-controller' => 'select2',
                ],
            ])
            ->add('lead', EntityType::class, [
                'class' => Lead::class,
                'label' => 'Related Lead',
                'required' => false,
                'placeholder' => 'Select lead...',
                'choice_label' => function (Lead $lead) {
                    return $lead->getCompanyName() ?: $lead->getEmail();
                },
                'query_builder' => function (EntityRepository $er) {
                    return $er->createQueryBuilder('l')
                        ->where('l.reviewStatus NOT IN (:closed)')
                        ->setParameter('closed', ['denied'])
                        ->orderBy('l.createdAt', 'DESC');
                },
                'attr' => [
                    'class' => 'rams-select',
                    'data-controller' => 'select2',
                ],
            ])
            ->add('rfq', EntityType::class, [
                'class' => RFQ::class,
                'label' => 'Related RFQ',
                'required' => false,
                'placeholder' => 'Select RFQ...',
                'choice_label' => function (RFQ $rfq) {
                    return $rfq->getRfqNumber() . ' - ' . ($rfq->getCompany()?->getName() ?? 'No Company');
                },
                'query_builder' => function (EntityRepository $er) {
                    return $er->createQueryBuilder('r')
                        ->orderBy('r.createdAt', 'DESC')
                        ->setMaxResults(100);
                },
                'attr' => [
                    'class' => 'rams-select',
                    'data-controller' => 'select2',
                ],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => CalendarEvent::class,
        ]);
    }
}
