<?php

namespace App\Form;

use App\Entity\EmailCampaign;
use Symfony\Component\Form\AbstractType;
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
                'label' => 'Campaign Name',
                'attr' => ['class' => 'geist-input', 'placeholder' => 'Q4 Automotive Outreach'],
            ])
            ->add('language', ChoiceType::class, [
                'label' => 'Language',
                'choices' => [
                    'English' => 'EN',
                    'French' => 'FR',
                    'Bilingual' => 'Bilingual',
                ],
                'attr' => ['class' => 'geist-select'],
            ])
            ->add('description', TextareaType::class, [
                'label' => 'Description',
                'required' => false,
                'attr' => ['class' => 'geist-textarea', 'rows' => 4, 'placeholder' => 'Campaign objectives and target audience...'],
            ])
            ->add('touchCount', IntegerType::class, [
                'label' => 'Number of Touches',
                'data' => 5,
                'attr' => [
                    'class' => 'geist-input',
                    'min' => 1,
                    'max' => 10,
                    'placeholder' => '5',
                ],
            ])
            ->add('active', CheckboxType::class, [
                'label' => 'Active',
                'required' => false,
                'data' => true,
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => EmailCampaign::class,
        ]);
    }
}
