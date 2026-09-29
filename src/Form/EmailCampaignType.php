<?php

namespace App\Form;

use App\Entity\EmailCampaign;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class EmailCampaignType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, [
                'label' => 'email_campaign.campaign_name',
                'constraints' => [
                    new Assert\NotBlank(['message' => 'Please enter a campaign name']),
                    new Assert\Length(['max' => 255]),
                ],
                'attr' => ['class' => 'rams-form__input', 'placeholder' => 'email_campaign.form.name_placeholder'],
            ])
            ->add('language', ChoiceType::class, [
                'label' => 'profile.language',
                'choices' => [
                    'language.english' => 'EN',
                    'language.french' => 'FR',
                    'language.bilingual' => 'Bilingual',
                ],
                'choice_translation_domain' => 'messages',
                'attr' => ['class' => 'rams-form__select'],
            ])
            ->add('description', TextareaType::class, [
                'label' => 'common.description',
                'required' => false,
                'attr' => ['class' => 'rams-form__textarea', 'rows' => 4, 'placeholder' => 'email_campaign.form.description_placeholder'],
            ])
            // ── Delivery content: the canonical renderer resolves these
            // (variant → touch template → campaign defaults); without them
            // exposed, configuring a campaign's actual email was impossible.
            ->add('subject', TextType::class, [
                'label' => 'email_campaign.form.subject',
                'required' => false,
                'attr' => ['class' => 'rams-form__input', 'placeholder' => 'email_campaign.form.subject_placeholder'],
            ])
            ->add('bodyHtml', TextareaType::class, [
                'label' => 'email_campaign.form.body_html',
                'required' => false,
                'attr' => ['class' => 'rams-form__textarea', 'rows' => 10, 'placeholder' => 'email_campaign.form.body_html_placeholder'],
            ])
            ->add('fromName', TextType::class, [
                'label' => 'email_campaign.form.from_name',
                'required' => false,
                'attr' => ['class' => 'rams-form__input', 'placeholder' => 'Starz Electronics'],
            ])
            ->add('fromEmail', EmailType::class, [
                'label' => 'email_campaign.form.from_email',
                'required' => false,
                'attr' => ['class' => 'rams-form__input', 'placeholder' => 'contact@starzelectronics.site'],
            ])
            ->add('touchCount', IntegerType::class, [
                'label' => 'email_campaign.form.touch_count',
                'attr' => [
                    'class' => 'rams-form__input',
                    'min' => 1,
                    'max' => 10,
                    'placeholder' => 'email_campaign.form.touch_count_placeholder',
                ],
            ])
            ->add('active', CheckboxType::class, [
                'label' => 'common.active',
                'required' => false,
                'data' => true,
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => EmailCampaign::class,
            'translation_domain' => 'messages',
        ]);
    }
}
