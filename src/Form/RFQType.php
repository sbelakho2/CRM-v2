<?php

namespace App\Form;

use App\Entity\Company;
use App\Entity\RFQ;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\MoneyType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Intl\Currencies;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\NotBlank;

class RFQType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $currencyChoices = [];
        foreach (Currencies::getNames() as $code => $name) {
            $currencyChoices["{$code} - {$name}"] = $code;
        }
        ksort($currencyChoices);

        $builder
            ->add('company', EntityType::class, [
                'class' => Company::class,
                'choice_label' => 'name',
                'placeholder' => 'rfq.form.select_company',
                'required' => true,
                'constraints' => [
                    new NotBlank(['message' => 'validation.required']),
                ],
                'attr' => ['class' => 'rams-form__select'],
            ])
            ->add('rfqNumber', TextType::class, [
                'required' => false,
                'label' => 'rfq.rfq_number',
                'attr' => [
                    'class' => 'rams-form__input',
                    'placeholder' => 'rfq.form.rfq_number_placeholder',
                ],
            ])
            ->add('rfqDate', DateType::class, [
                'required' => false,
                'widget' => 'single_text',
                'label' => 'rfq.form.rfq_date',
                'attr' => ['class' => 'rams-form__input'],
            ])
            ->add('type', ChoiceType::class, [
                'choices' => [
                    'rfq.types.standard' => 'Standard RFQ',
                    'rfq.types.npi' => 'NPI',
                    'rfq.types.framework' => 'Framework Agreement',
                ],
                'choice_translation_domain' => 'messages',
                'required' => true,
                'label' => 'rfq.form.rfq_type',
                'attr' => ['class' => 'rams-form__select'],
            ])
            ->add('status', ChoiceType::class, [
                'choices' => [
                    'rfq.statuses.pending' => 'Pending',
                    'rfq.statuses.in_review' => 'In Review',
                    'rfq.statuses.submitted' => 'Submitted',
                    'rfq.statuses.won' => 'Won',
                    'rfq.statuses.lost' => 'Lost',
                ],
                'required' => true,
                'choice_translation_domain' => 'messages',
                'attr' => ['class' => 'rams-form__select'],
            ])
            ->add('currency', ChoiceType::class, [
                'choices' => $currencyChoices,
                'required' => false,
                'label' => 'rfq.currency',
                'attr' => ['class' => 'rams-form__select'],
            ])
            ->add('estimatedValue', MoneyType::class, [
                'required' => false,
                'currency' => false,
                'label' => 'rfq.estimated_value',
                'attr' => [
                    'class' => 'rams-form__input',
                    'placeholder' => '0.00',
                ],
            ])
            ->add('volumeAnnual', IntegerType::class, [
                'required' => false,
                'label' => 'rfq.form.annual_volume',
                'attr' => [
                    'class' => 'rams-form__input',
                    'placeholder' => 'rfq.form.annual_volume_placeholder',
                ],
            ])
            ->add('sopDate', DateType::class, [
                'required' => false,
                'widget' => 'single_text',
                'label' => 'rfq.form.sop_date',
                'attr' => ['class' => 'rams-form__input'],
            ])
            ->add('technicalScope', TextareaType::class, [
                'required' => false,
                'label' => 'rfq.form.technical_scope',
                'attr' => [
                    'class' => 'rams-form__input',
                    'rows' => 4,
                    'placeholder' => 'rfq.form.technical_scope_placeholder',
                ],
            ])
            ->add('notes', TextareaType::class, [
                'required' => false,
                'attr' => [
                    'class' => 'rams-form__textarea',
                    'rows' => 4,
                    'placeholder' => 'rfq.form.notes_placeholder',
                ],
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => RFQ::class,
            'translation_domain' => 'messages',
        ]);
    }
}
