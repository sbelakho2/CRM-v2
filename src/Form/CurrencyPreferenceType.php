<?php

namespace App\Form;

use App\Entity\User;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class CurrencyPreferenceType extends AbstractType
{
    private const MAJOR_CURRENCIES = ['USD', 'EUR', 'GBP', 'JPY', 'CNY', 'CAD', 'AUD', 'CHF', 'MAD', 'AED'];
    /**
     * @param array<string|int, mixed> $options
     */
    public /**
 * @param array<string|int, mixed> $options
 */
function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $currencyChoices = array_combine(self::MAJOR_CURRENCIES, self::MAJOR_CURRENCIES);

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
            ])
            ->add('preferredTheme', ChoiceType::class, [
                'choices' => [
                    'profile.themes.system' => 'system',
                    'profile.themes.light' => 'light',
                    'profile.themes.dark' => 'dark',
                ],
                'required' => false,
                'label' => 'profile.theme',
                'placeholder' => 'profile.theme_placeholder',
                'choice_translation_domain' => 'messages',
                'attr' => ['class' => 'rams-form__select'],
            ])
            ->add('accentColor', ChoiceType::class, [
                'choices' => [
                    'profile.accents.orange' => 'orange',
                    'profile.accents.blue' => 'blue',
                    'profile.accents.green' => 'green',
                    'profile.accents.purple' => 'purple',
                    'profile.accents.red' => 'red',
                ],
                'required' => false,
                'label' => 'profile.accent_color',
                'placeholder' => 'profile.accent_placeholder',
                'choice_translation_domain' => 'messages',
                'attr' => ['class' => 'rams-form__select'],
            ])
            ->add('fontSize', ChoiceType::class, [
                'choices' => [
                    'profile.font_sizes.small' => 'small',
                    'profile.font_sizes.medium' => 'medium',
                    'profile.font_sizes.large' => 'large',
                ],
                'required' => false,
                'label' => 'profile.font_size',
                'placeholder' => 'profile.font_size_placeholder',
                'choice_translation_domain' => 'messages',
                'attr' => ['class' => 'rams-form__select'],
            ])
            ->add('density', ChoiceType::class, [
                'choices' => [
                    'profile.densities.comfortable' => 'comfortable',
                    'profile.densities.compact' => 'compact',
                ],
                'required' => false,
                'label' => 'profile.density',
                'placeholder' => 'profile.density_placeholder',
                'choice_translation_domain' => 'messages',
                'attr' => ['class' => 'rams-form__select'],
            ])
            ->add('reducedMotion', CheckboxType::class, [
                'required' => false,
                'label' => 'profile.reduced_motion',
                'attr' => ['class' => 'rams-checkbox'],
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
