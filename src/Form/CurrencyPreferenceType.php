<?php

namespace App\Form;

use App\Entity\User;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Intl\Currencies;
use Symfony\Component\OptionsResolver\OptionsResolver;

class CurrencyPreferenceType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $currencyChoices = [];
        foreach (Currencies::getNames() as $code => $name) {
            $currencyChoices["{$code} - {$name}"] = $code;
        }
        ksort($currencyChoices);

        $languageChoices = [
            'language.english' => 'en',
            'language.french' => 'fr',
            'language.arabic' => 'ar',
        ];

        $timezoneChoices = [];
        foreach (\DateTimeZone::listIdentifiers() as $timezone) {
            $timezoneChoices[$timezone] = $timezone;
        }

        $builder->add('displayCurrency', ChoiceType::class, [
            'choices' => $currencyChoices,
            'required' => false,
            'label' => 'profile.display_currency',
            'attr' => ['class' => 'rams-form__select'],
        ]);

        $builder
            ->add('preferredLocale', ChoiceType::class, [
                'choices' => $languageChoices,
                'required' => false,
                'label' => 'profile.language',
                'placeholder' => 'profile.language_placeholder',
                'choice_translation_domain' => 'messages',
                'attr' => ['class' => 'rams-form__select'],
            ])
            ->add('preferredTimezone', ChoiceType::class, [
                'choices' => $timezoneChoices,
                'required' => false,
                'label' => 'profile.timezone',
                'placeholder' => 'profile.timezone_placeholder',
                'attr' => ['class' => 'rams-form__select'],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => User::class,
            'translation_domain' => 'messages',
        ]);
    }
}
