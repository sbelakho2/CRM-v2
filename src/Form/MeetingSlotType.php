<?php

namespace App\Form;

use App\Entity\MeetingSlot;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateTimeType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\UrlType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class MeetingSlotType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('title', TextType::class, [
                'label' => 'Meeting Title',
                'attr' => [
                    'class' => 'rams-input',
                    'placeholder' => 'e.g., 30-min Discovery Call',
                ],
            ])
            ->add('description', TextareaType::class, [
                'label' => 'Description',
                'required' => false,
                'attr' => [
                    'class' => 'rams-input',
                    'rows' => 3,
                    'placeholder' => 'What will be discussed in this meeting...',
                ],
            ])
            ->add('meetingType', ChoiceType::class, [
                'label' => 'Meeting Type',
                'choices' => MeetingSlot::getMeetingTypes(),
                'attr' => ['class' => 'rams-select'],
            ])
            ->add('durationMinutes', ChoiceType::class, [
                'label' => 'Duration',
                'choices' => MeetingSlot::getDurations(),
                'attr' => ['class' => 'rams-select'],
            ])
            ->add('startTime', DateTimeType::class, [
                'label' => 'Start Time',
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
                'attr' => ['class' => 'rams-input'],
            ])
            ->add('location', TextType::class, [
                'label' => 'Location',
                'required' => false,
                'attr' => [
                    'class' => 'rams-input',
                    'placeholder' => 'e.g., Conference Room A, or "Virtual"',
                ],
            ])
            ->add('meetingUrl', UrlType::class, [
                'label' => 'Meeting URL',
                'required' => false,
                'attr' => [
                    'class' => 'rams-input',
                    'placeholder' => 'https://zoom.us/j/...',
                ],
            ])
            ->add('meetingProvider', ChoiceType::class, [
                'label' => 'Meeting Provider',
                'required' => false,
                'choices' => [
                    'None' => null,
                    'Zoom' => 'zoom',
                    'Microsoft Teams' => 'teams',
                    'Google Meet' => 'google_meet',
                    'Webex' => 'webex',
                    'Other' => 'other',
                ],
                'attr' => ['class' => 'rams-select'],
            ])
            ->add('timezone', ChoiceType::class, [
                'label' => 'Timezone',
                'choices' => $this->getTimezoneChoices(),
                'attr' => ['class' => 'rams-select'],
            ])
        ;
    }
    
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => MeetingSlot::class,
        ]);
    }
    
    private function getTimezoneChoices(): array
    {
        $timezones = [
            'America/New_York' => 'Eastern Time (US & Canada)',
            'America/Chicago' => 'Central Time (US & Canada)',
            'America/Denver' => 'Mountain Time (US & Canada)',
            'America/Los_Angeles' => 'Pacific Time (US & Canada)',
            'America/Phoenix' => 'Arizona',
            'America/Anchorage' => 'Alaska',
            'Pacific/Honolulu' => 'Hawaii',
            'UTC' => 'UTC',
            'Europe/London' => 'London',
            'Europe/Paris' => 'Paris',
            'Europe/Berlin' => 'Berlin',
            'Asia/Tokyo' => 'Tokyo',
            'Asia/Shanghai' => 'Shanghai',
            'Asia/Singapore' => 'Singapore',
            'Australia/Sydney' => 'Sydney',
        ];
        
        return array_flip($timezones);
    }
}
