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
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\NotBlank;

class RFQType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('company', EntityType::class, [
                'class' => Company::class,
                'choice_label' => 'name',
                'placeholder' => 'Select company',
                'required' => true,
                'constraints' => [
                    new NotBlank(['message' => 'Please select a company']),
                ],
                'attr' => ['class' => 'form-select'],
            ])
            ->add('rfqNumber', TextType::class, [
                'required' => false,
                'label' => 'RFQ Number',
                'attr' => [
                    'class' => 'form-input',
                    'placeholder' => 'e.g., RFQ-2025-001',
                ],
            ])
            ->add('rfqDate', DateType::class, [
                'required' => false,
                'widget' => 'single_text',
                'label' => 'RFQ Date',
                'attr' => ['class' => 'form-input'],
            ])
            ->add('type', ChoiceType::class, [
                'choices' => [
                    'Standard RFQ' => 'Standard RFQ',
                    'NPI (New Product Introduction)' => 'NPI',
                    'Framework Agreement' => 'Framework Agreement',
                ],
                'required' => true,
                'label' => 'RFQ Type',
                'attr' => ['class' => 'form-select'],
            ])
            ->add('status', ChoiceType::class, [
                'choices' => [
                    'Pending' => 'Pending',
                    'In Review' => 'In Review',
                    'Submitted' => 'Submitted',
                    'Won' => 'Won',
                    'Lost' => 'Lost',
                ],
                'required' => true,
                'attr' => ['class' => 'form-select'],
            ])
            ->add('estimatedValue', MoneyType::class, [
                'required' => false,
                'currency' => 'EUR',
                'label' => 'Estimated Value (€)',
                'attr' => [
                    'class' => 'form-input',
                    'placeholder' => '0.00',
                ],
            ])
            ->add('volumeAnnual', IntegerType::class, [
                'required' => false,
                'label' => 'Annual Volume',
                'attr' => [
                    'class' => 'form-input',
                    'placeholder' => 'e.g., 10000',
                ],
            ])
            ->add('sopDate', DateType::class, [
                'required' => false,
                'widget' => 'single_text',
                'label' => 'SOP Date (Start of Production)',
                'attr' => ['class' => 'form-input'],
            ])
            ->add('technicalScope', TextareaType::class, [
                'required' => false,
                'label' => 'Technical Scope',
                'attr' => [
                    'class' => 'form-input',
                    'rows' => 4,
                    'placeholder' => 'Describe the technical requirements and scope...',
                ],
            ])
            ->add('notes', TextareaType::class, [
                'required' => false,
                'attr' => [
                    'class' => 'form-input',
                    'rows' => 4,
                    'placeholder' => 'Add any additional notes or comments...',
                ],
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => RFQ::class,
        ]);
    }
}
