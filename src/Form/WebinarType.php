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
                'label' => 'Webinar Title',
                'attr' => ['placeholder' => 'e.g., EMS Excellence: ISO 9001 & IATF 16949'],
            ])
            ->add('description', TextareaType::class, [
                'label' => 'Description',
                'required' => false,
                'attr' => [
                    'rows' => 5,
                    'placeholder' => 'Describe the webinar content, topics covered, and target audience...',
                ],
            ])
            ->add('scheduledDate', DateTimeType::class, [
                'label' => 'Scheduled Date & Time',
                'widget' => 'single_text',
            ])
            ->add('language', ChoiceType::class, [
                'label' => 'Language',
                'choices' => [
                    'English' => 'EN',
                    'French' => 'FR',
                    'Bilingual (EN/FR)' => 'EN/FR',
                ],
            ])
            ->add('maxAttendees', IntegerType::class, [
                'label' => 'Maximum Attendees',
                'required' => false,
                'attr' => ['placeholder' => 'Leave empty for unlimited'],
            ])
            ->add('meetingUrl', UrlType::class, [
                'label' => 'Meeting URL (Zoom/Teams)',
                'required' => false,
                'attr' => ['placeholder' => 'https://zoom.us/j/...'],
            ])
            ->add('recordingUrl', UrlType::class, [
                'label' => 'Recording URL',
                'required' => false,
                'attr' => ['placeholder' => 'Will be added after the webinar'],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Webinar::class,
        ]);
    }
}
