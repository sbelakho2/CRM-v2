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
    public function __construct(
        private \Doctrine\ORM\EntityManagerInterface $entityManager,
    ) {}

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
            ->add('touchTemplates', TextareaType::class, [
                'label' => 'email_campaign.form.touch_templates',
                'required' => false,
                'attr' => ['class' => 'rams-form__textarea rams-font-mono', 'rows' => 4, 'placeholder' => '{"1": {"template_id": 12}, "2": {"template_id": 13, "delay_value": 3}}'],
                'constraints' => [
                    new Assert\Callback([$this, 'validateTouchTemplates']),
                ],
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
                // NO 'data' => true: overriding the bound model value
                // silently re-activated paused campaigns on edit.
            ])
        ;

        // touchTemplates: entity stores an array; the field edits JSON text.
        $builder->get('touchTemplates')->addModelTransformer(
            new \Symfony\Component\Form\CallbackTransformer(
                static fn ($array): string => $array === null || $array === []
                    ? ''
                    : (json_encode($array, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: ''),
                static function (?string $json): array {
                    if ($json === null || trim($json) === '') {
                        return [];
                    }
                    /** @var array<string, mixed>|null $decoded */
                    $decoded = json_decode($json, true);

                    return is_array($decoded) ? $decoded : [];
                }
            )
        );
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => EmailCampaign::class,
            'translation_domain' => 'messages',
        ]);
    }

    /**
     * Touch templates: JSON map of touchNumber → {template_id, delay_value?,
     * conditions?}. Keys must be 1..touchCount; template_id must exist.
     */
    public function validateTouchTemplates($value, \Symfony\Component\Validator\Context\ExecutionContextInterface $context): void
    {
        // The constraint may run against the decoded ARRAY (model data,
        // post reverse-transform) or the raw JSON STRING (view data).
        $decoded = $value;
        if (is_string($value)) {
            if (trim($value) === '') {
                return;
            }
            /** @var array<string, mixed>|null $decoded */
            $decoded = json_decode($value, true);
            if (!is_array($decoded)) {
                $context->buildViolation('Touch templates must be valid JSON.')->addViolation();

                return;
            }
        } elseif (!is_array($value)) {
            return; // null/empty model
        }

        // touchCount lives on the parent form data (the entity being bound).
        $root = $context->getRoot();
        $touchCount = null;
        if ($root instanceof EmailCampaign) {
            $touchCount = $root->getTouchCount();
        } elseif (is_array($root) && isset($root['touchCount'])) {
            $touchCount = (int) $root['touchCount'];
        }

        foreach ($decoded as $touch => $config) {
            if (!ctype_digit((string) $touch) || (int) $touch < 1) {
                $context->buildViolation(sprintf('Touch key "%s" must be a positive integer.', $touch))->addViolation();
                continue;
            }
            if ($touchCount !== null && $touchCount > 0 && (int) $touch > $touchCount) {
                $context->buildViolation(sprintf('Touch %s exceeds the campaign touch count (%d).', $touch, $touchCount))->addViolation();
                continue;
            }
            if (!is_array($config) || !isset($config['template_id']) || !ctype_digit((string) $config['template_id'])) {
                $context->buildViolation(sprintf('Touch %s needs a numeric template_id.', $touch))->addViolation();
                continue;
            }
            // template_id must reference an EXISTING template.
            $template = $this->entityManager->find(\App\Entity\EmailTemplate::class, (int) $config['template_id']);
            if ($template === null) {
                $context->buildViolation(sprintf('Touch %s references template %s which does not exist.', $touch, $config['template_id']))->addViolation();
            }
        }
    }
}

