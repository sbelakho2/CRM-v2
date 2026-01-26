<?php

namespace App\Form;

use App\Entity\Webinar;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\DateTimeType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\UrlType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class WebinarType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('title', TextType::class, [
                'label' => 'webinar.webinar_title',
                'attr' => ['placeholder' => 'webinar.form.title_placeholder'],
            ])
            ->add('description', TextareaType::class, [
                'label' => 'common.description',
                'required' => false,
                'attr' => [
                    'rows' => 5,
                    'placeholder' => 'webinar.form.description_placeholder',
                ],
            ])
            ->add('scheduledDate', DateTimeType::class, [
                'label' => 'webinar.form.scheduled_date',
                'widget' => 'single_text',
            ])
            ->add('language', ChoiceType::class, [
                'label' => 'profile.language',
                'choices' => [
                    'language.english' => 'EN',
                    'language.french' => 'FR',
                    'language.bilingual_en_fr' => 'EN/FR',
                ],
                'choice_translation_domain' => 'messages',
            ])
            ->add('maxAttendees', IntegerType::class, [
                'label' => 'webinar.max_attendees',
                'required' => false,
                'attr' => ['placeholder' => 'webinar.form.max_attendees_placeholder'],
            ])
            ->add('meetingUrl', UrlType::class, [
                'label' => 'webinar.form.meeting_url',
                'required' => false,
                'attr' => ['placeholder' => 'webinar.form.meeting_url_placeholder'],
            ])
            ->add('recordingUrl', UrlType::class, [
                'label' => 'webinar.recording',
                'required' => false,
                'attr' => ['placeholder' => 'webinar.form.recording_url_placeholder'],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Webinar::class,
            'translation_domain' => 'messages',
        ]);
    }
}
