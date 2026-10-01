<?php

namespace App\Form;

use App\Entity\Task;
use App\Entity\User;
use App\Entity\Company;
use App\Entity\Contact;
use App\Entity\RFQ;
use App\Entity\Lead;
use Doctrine\ORM\EntityRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\TimeType;
use Symfony\Component\Form\Extension\Core\Type\DateTimeType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\OptionsResolver\OptionsResolver;

class TaskType extends AbstractType
{
    /**
     * @param array<string|int, mixed> $options
     */
    public /**
 * @param array<string|int, mixed> $options
 */
function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('title', TextType::class, [
                'label' => 'Task Title',
                'constraints' => [
                    new Assert\NotBlank(['message' => 'Please enter a task title']),
                    new Assert\Length(['max' => 255]),
                ],
                'attr' => [
                    'class' => 'rams-input',
                    'placeholder' => 'Enter task title...',
                    'autofocus' => true,
                ],
            ])
            ->add('description', TextareaType::class, [
                'label' => 'Description',
                'required' => false,
                'attr' => [
                    'class' => 'rams-input',
                    'rows' => 4,
                    'placeholder' => 'Add more details about this task...',
                ],
            ])
            ->add('type', ChoiceType::class, [
                'label' => 'Task Type',
                'choices' => array_flip(Task::TYPE_LABELS),
                'attr' => ['class' => 'rams-select'],
            ])
            ->add('priority', ChoiceType::class, [
                'label' => 'Priority',
                'choices' => array_flip(Task::PRIORITY_LABELS),
                'attr' => ['class' => 'rams-select'],
            ])
            ->add('status', ChoiceType::class, [
                'label' => 'Status',
                'choices' => array_flip(Task::STATUS_LABELS),
                'attr' => ['class' => 'rams-select'],
            ])
            ->add('dueDate', DateType::class, [
                'label' => 'Due Date',
                'required' => false,
                'widget' => 'single_text',
                'attr' => ['class' => 'rams-input'],
            ])
            ->add('dueTime', TimeType::class, [
                'label' => 'Due Time',
                'required' => false,
                'widget' => 'single_text',
                'attr' => ['class' => 'rams-input'],
            ])
            ->add('estimatedMinutes', IntegerType::class, [
                'label' => 'Estimated Time (minutes)',
                'required' => false,
                'attr' => [
                    'class' => 'rams-input',
                    'min' => 0,
                    'placeholder' => '30',
                ],
            ])
            ->add('assignedTo', EntityType::class, [
                'label' => 'Assigned To',
                'class' => User::class,
                'required' => false,
                'placeholder' => '-- Unassigned --',
                'choice_label' => function (User $user) {
                    return $user->getFullName();
                },
                'query_builder' => function (EntityRepository $er) {
                    return $er->createQueryBuilder('u')
                        ->where('u.active = true')
                        ->orderBy('u.firstName', 'ASC');
                },
                'attr' => ['class' => 'rams-select'],
            ])
            ->add('company', EntityType::class, [
                'label' => 'Related Company',
                'class' => Company::class,
                'required' => false,
                'placeholder' => '-- None --',
                'choice_label' => 'name',
                'query_builder' => function (EntityRepository $er) {
                    return $er->createQueryBuilder('c')
                        ->orderBy('c.name', 'ASC');
                },
                'attr' => ['class' => 'rams-select'],
            ])
            ->add('contact', EntityType::class, [
                'label' => 'Related Contact',
                'class' => Contact::class,
                'required' => false,
                'placeholder' => '-- None --',
                'choice_label' => function (Contact $contact) {
                    $name = $contact->getFullName();
                    if ($contact->getCompany()) {
                        $name .= ' (' . $contact->getCompany()->getName() . ')';
                    }
                    return $name;
                },
                'query_builder' => function (EntityRepository $er) {
                    return $er->createQueryBuilder('c')
                        ->leftJoin('c.company', 'co')
                        ->orderBy('c.lastName', 'ASC');
                },
                'attr' => ['class' => 'rams-select'],
            ])
            ->add('rfq', EntityType::class, [
                'label' => 'Related RFQ',
                'class' => RFQ::class,
                'required' => false,
                'placeholder' => '-- None --',
                'choice_label' => function (RFQ $rfq) {
                    $label = 'RFQ #' . $rfq->getId();
                    if ($rfq->getCompany()) {
                        $label .= ' - ' . $rfq->getCompany()->getName();
                    }
                    return $label;
                },
                'query_builder' => function (EntityRepository $er) {
                    return $er->createQueryBuilder('r')
                        ->leftJoin('r.company', 'c')
                        ->orderBy('r.createdAt', 'DESC')
                        ->setMaxResults(100);
                },
                'attr' => ['class' => 'rams-select'],
            ])
            ->add('lead', EntityType::class, [
                'label' => 'Related Lead',
                'class' => Lead::class,
                'required' => false,
                'placeholder' => '-- None --',
                'choice_label' => 'companyName',
                'query_builder' => function (EntityRepository $er) {
                    return $er->createQueryBuilder('l')
                        ->orderBy('l.companyName', 'ASC')
                        ->setMaxResults(100);
                },
                'attr' => ['class' => 'rams-select'],
            ])
            ->add('reminderAt', DateTimeType::class, [
                'label' => 'Reminder',
                'required' => false,
                'widget' => 'single_text',
                'attr' => ['class' => 'rams-input'],
            ])
            ->add('isRecurring', CheckboxType::class, [
                'label' => 'Recurring Task',
                'required' => false,
                'attr' => ['class' => 'rams-checkbox'],
            ])
            ->add('recurringFrequency', ChoiceType::class, [
                'label' => 'Repeat',
                'required' => false,
                'placeholder' => '-- Select Frequency --',
                'choices' => [
                    'Daily' => 'daily',
                    'Weekly' => 'weekly',
                    'Monthly' => 'monthly',
                ],
                'attr' => ['class' => 'rams-select'],
            ])
            ->add('tags', TextType::class, [
                'label' => 'Tags (comma-separated)',
                'required' => false,
                'mapped' => false,
                'attr' => [
                    'class' => 'rams-input',
                    'placeholder' => 'urgent, client-facing, follow-up',
                ],
            ]);

        $builder->addEventListener(FormEvents::POST_SUBMIT, function (FormEvent $event): void {
            $task = $event->getData();
            if (!$task instanceof Task) {
                return;
            }

            $tagsString = $event->getForm()->get('tags')->getData();
            if (is_string($tagsString) && trim($tagsString) !== '') {
                $tags = array_values(array_filter(array_map('trim', explode(',', $tagsString)), static fn (string $tag): bool => $tag !== ''));
                $task->setTags($tags);
            } else {
                $task->setTags(null);
            }
        });
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Task::class,
        ]);
    }
}
