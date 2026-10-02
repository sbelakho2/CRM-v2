<?php

namespace App\Form;

use App\Entity\CustomFieldDefinition;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\OptionsResolver\OptionsResolver;

class CustomFieldDefinitionType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('label', TextType::class, [
                'label' => 'Field Label',
                'constraints' => [
                    new Assert\NotBlank(['message' => 'Please enter a field label']),
                    new Assert\Length(['max' => 255]),
                ],
                'attr' => [
                    'class' => 'rams-input',
                    'placeholder' => 'e.g., Industry Type, Contract Value',
                ],
                'help' => 'The label shown to users',
            ])
            ->add('fieldKey', TextType::class, [
                'label' => 'Field Key',
                'required' => false,
                'attr' => [
                    'class' => 'rams-input',
                    'placeholder' => 'e.g., industry_type (auto-generated if empty)',
                    'pattern' => '^[a-z][a-z0-9_]*$',
                ],
                'help' => 'Unique identifier (lowercase letters, numbers, underscores)',
            ])
            ->add('entityType', ChoiceType::class, [
                'label' => 'Entity Type',
                'choices' => CustomFieldDefinition::getEntityTypes(),
                'attr' => ['class' => 'rams-select'],
                'help' => 'Which record type this field belongs to',
            ])
            ->add('fieldType', ChoiceType::class, [
                'label' => 'Field Type',
                'choices' => CustomFieldDefinition::getFieldTypes(),
                'attr' => [
                    'class' => 'rams-select',
                    'data-controller' => 'field-type',
                ],
                'help' => 'The type of data this field stores',
            ])
            ->add('description', TextareaType::class, [
                'label' => 'Description',
                'required' => false,
                'attr' => [
                    'class' => 'rams-input',
                    'rows' => 2,
                    'placeholder' => 'Optional help text for users',
                ],
            ])
            ->add('placeholder', TextType::class, [
                'label' => 'Placeholder',
                'required' => false,
                'attr' => [
                    'class' => 'rams-input',
                    'placeholder' => 'Text shown when field is empty',
                ],
            ])
            ->add('defaultValue', TextType::class, [
                'label' => 'Default Value',
                'required' => false,
                'attr' => [
                    'class' => 'rams-input',
                ],
            ])
            ->add('fieldGroup', TextType::class, [
                'label' => 'Field Group',
                'required' => false,
                'attr' => [
                    'class' => 'rams-input',
                    'placeholder' => 'e.g., Financial Info, Technical Details',
                ],
                'help' => 'Group related fields together',
            ])
            ->add('options', TextareaType::class, [
                'label' => 'Options (for dropdowns)',
                'required' => false,
                'mapped' => false,
                'attr' => [
                    'class' => 'rams-input options-field',
                    'rows' => 5,
                    'placeholder' => "Option 1\nOption 2\nOption 3",
                ],
                'help' => 'One option per line',
            ])
            ->add('isRequired', CheckboxType::class, [
                'label' => 'Required field',
                'required' => false,
                'attr' => ['class' => 'rams-checkbox'],
            ])
            ->add('isActive', CheckboxType::class, [
                'label' => 'Active',
                'required' => false,
                'attr' => ['class' => 'rams-checkbox'],
                'data' => true,
            ])
            ->add('isSearchable', CheckboxType::class, [
                'label' => 'Searchable',
                'required' => false,
                'attr' => ['class' => 'rams-checkbox'],
                'help' => 'Include in search results',
            ])
            ->add('showInList', CheckboxType::class, [
                'label' => 'Show in list view',
                'required' => false,
                'attr' => ['class' => 'rams-checkbox'],
            ])
            ->add('showInDetail', CheckboxType::class, [
                'label' => 'Show in detail view',
                'required' => false,
                'attr' => ['class' => 'rams-checkbox'],
                'data' => true,
            ])
            ->add('sortOrder', IntegerType::class, [
                'label' => 'Sort Order',
                'required' => false,
                'attr' => [
                    'class' => 'rams-input',
                    'min' => 0,
                ],
            ]);

        // Handle options conversion
        $builder->addEventListener(FormEvents::PRE_SET_DATA, function (FormEvent $event) {
            $field = $event->getData();
            $form = $event->getForm();

            if (!$field instanceof CustomFieldDefinition) {
                return;
            }

            if ($field->getOptions()) {
                $optionsText = implode("\n", array_map(
                    static function (mixed $opt): string {
                        if (is_array($opt)) {
                            $label = $opt['label'] ?? $opt['value'] ?? '';
                            return is_scalar($label) ? (string) $label : '';
                        }
                        return is_scalar($opt) ? (string) $opt : '';
                    },
                    $field->getOptions()
                ));

                $form->get('options')->setData($optionsText);
            }
        });

        $builder->addEventListener(FormEvents::POST_SUBMIT, function (FormEvent $event) {
            $field = $event->getData();
            $form = $event->getForm();

            if (!$field instanceof CustomFieldDefinition) {
                return;
            }

            // Convert options text to array
            $optionsText = $form->get('options')->getData();
            if (is_string($optionsText) && $optionsText !== '' && $field->hasOptions()) {
                $sanitized = array_map('trim', array_filter(explode("\n", $optionsText)));
                $sanitized = array_values(array_map('strip_tags', $sanitized));
                $options = array_map(static function (string $line): array {
                    return ['label' => $line, 'value' => $line];
                }, $sanitized);
                $field->setOptions($options);
            }

            // Auto-generate field key
            if (!$field->getFieldKey() && $field->getLabel()) {
                $field->setFieldKey($field->generateFieldKey());
            }
        });
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => CustomFieldDefinition::class,
        ]);
    }
}
