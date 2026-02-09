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
                'label' => 'meeting.form.title',
                'attr' => [
                    'class' => 'rams-input',
                    'placeholder' => 'meeting.form.title_placeholder',
                ],
            ])
            ->add('description', TextareaType::class, [
                'label' => 'meeting.form.description',
                'required' => false,
                'attr' => [
                    'class' => 'rams-input',
                    'rows' => 3,
                    'placeholder' => 'meeting.form.description_placeholder',
                ],
            ])
            ->add('meetingType', ChoiceType::class, [
                'label' => 'meeting.form.type',
                'choices' => MeetingSlot::getMeetingTypes(),
                'choice_translation_domain' => 'messages',
                'attr' => ['class' => 'rams-select'],
            ])
            ->add('durationMinutes', ChoiceType::class, [
                'label' => 'meeting.form.duration',
                'choices' => MeetingSlot::getDurations(),
                'choice_translation_domain' => 'messages',
                'attr' => ['class' => 'rams-select'],
            ])
            ->add('startTime', DateTimeType::class, [
                'label' => 'meeting.form.start_time',
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
                'attr' => ['class' => 'rams-input'],
            ])
            ->add('location', TextType::class, [
                'label' => 'meeting.form.location',
                'required' => false,
                'attr' => [
                    'class' => 'rams-input',
                    'placeholder' => 'meeting.form.location_placeholder',
                ],
            ])
            ->add('meetingUrl', UrlType::class, [
                'label' => 'meeting.form.url',
                'required' => false,
                'attr' => [
                    'class' => 'rams-input',
                    'placeholder' => 'meeting.form.url_placeholder',
                ],
            ])
            ->add('meetingProvider', ChoiceType::class, [
                'label' => 'meeting.form.provider',
                'required' => false,
                'choices' => [
                    'meeting.providers.none' => null,
                    'meeting.providers.zoom' => 'zoom',
                    'meeting.providers.teams' => 'teams',
                    'meeting.providers.google_meet' => 'google_meet',
                    'meeting.providers.webex' => 'webex',
                    'meeting.providers.other' => 'other',
                ],
                'choice_translation_domain' => 'messages',
                'attr' => ['class' => 'rams-select'],
            ])
            ->add('timezone', ChoiceType::class, [
                'label' => 'meeting.form.timezone',
                'choices' => $this->getTimezoneChoices(),
                'choice_translation_domain' => 'messages',
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
