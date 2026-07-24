<?php

namespace App\Form;

use App\Entity\EmailCampaign;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
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
                'attr' => ['class' => 'geist-input', 'placeholder' => 'email_campaign.form.name_placeholder'],
            ])
            ->add('language', ChoiceType::class, [
                'label' => 'profile.language',
                'choices' => [
                    'language.english' => 'EN',
                    'language.french' => 'FR',
                    'language.bilingual' => 'Bilingual',
                ],
                'choice_translation_domain' => 'messages',
                'attr' => ['class' => 'geist-select'],
            ])
            ->add('description', TextareaType::class, [
                'label' => 'common.description',
                'required' => false,
                'attr' => ['class' => 'geist-textarea', 'rows' => 4, 'placeholder' => 'email_campaign.form.description_placeholder'],
            ])
            ->add('touchCount', IntegerType::class, [
                'label' => 'email_campaign.form.touch_count',
                'attr' => [
                    'class' => 'geist-input',
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
