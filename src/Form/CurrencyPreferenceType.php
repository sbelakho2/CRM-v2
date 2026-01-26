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

        $builder->add('displayCurrency', ChoiceType::class, [
            'choices' => $currencyChoices,
            'required' => false,
            'label' => 'Display Currency',
            'attr' => ['class' => 'rams-form__select'],
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => User::class,
        ]);
    }
}
