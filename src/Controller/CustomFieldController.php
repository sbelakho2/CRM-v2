<?php

namespace App\Controller;

use App\Entity\CustomFieldDefinition;
use App\Form\CustomFieldDefinitionType;
use App\Repository\CustomFieldDefinitionRepository;
use App\Service\CustomFieldService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin/custom-fields')]
#[IsGranted('ROLE_ADMIN')]
class CustomFieldController extends AbstractController
{
    public function __construct(
        private readonly CustomFieldDefinitionRepository $definitionRepository,
        private readonly CustomFieldService $customFieldService,
        private readonly EntityManagerInterface $entityManager,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('', name: 'custom_field_index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $entityType = $request->query->get('entity', CustomFieldDefinition::ENTITY_COMPANY);
        
        $fields = $this->definitionRepository->findByEntityType($entityType, false);
        $statistics = $this->definitionRepository->getStatistics();

        return $this->render('custom_field/index.html.twig', [
            'fields' => $fields,
            'currentEntityType' => $entityType,
            'entityTypes' => CustomFieldDefinition::getEntityTypes(),
            'fieldTypes' => CustomFieldDefinition::getFieldTypes(),
            'statistics' => $statistics,
        ]);
    }

    #[Route('/new', name: 'custom_field_new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $field = new CustomFieldDefinition();
        
        if ($request->query->has('entity')) {
            $field->setEntityType($request->query->get('entity'));
        }

        $form = $this->createForm(CustomFieldDefinitionType::class, $field);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $field->setCreatedBy($this->getUser());
            $this->customFieldService->createField($field);

            $this->addFlash('success', $this->translator->trans('custom_field.flash.created'));

            return $this->redirectToRoute('custom_field_index', [
                'entity' => $field->getEntityType(),
            ]);
        }

        return $this->render('custom_field/new.html.twig', [
            'form' => $form,
            'field' => $field,
        ]);
    }

    #[Route('/{id}', name: 'custom_field_show', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function show(CustomFieldDefinition $field): Response
    {
        return $this->render('custom_field/show.html.twig', [
            'field' => $field,
        ]);
    }

    #[Route('/{id}/edit', name: 'custom_field_edit', methods: ['GET', 'POST'])]
    public function edit(Request $request, CustomFieldDefinition $field): Response
    {
        $form = $this->createForm(CustomFieldDefinitionType::class, $field);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->entityManager->flush();

            $this->addFlash('success', $this->translator->trans('custom_field.flash.updated'));

            return $this->redirectToRoute('custom_field_index', [
                'entity' => $field->getEntityType(),
            ]);
        }

        return $this->render('custom_field/edit.html.twig', [
            'form' => $form,
            'field' => $field,
        ]);
    }

    #[Route('/{id}/delete', name: 'custom_field_delete', methods: ['POST'])]
    public function delete(Request $request, CustomFieldDefinition $field): Response
    {
        $entityType = $field->getEntityType();

        if ($this->isCsrfTokenValid('delete' . $field->getId(), $request->request->get('_token'))) {
            // Check if field has values
            $valueCount = count($field->getValues());
            
            if ($valueCount > 0 && !$request->request->getBoolean('confirm_delete_values')) {
                $this->addFlash('warning', $this->translator->trans('custom_field.flash.delete_warning', ['%count%' => $valueCount]));
                return $this->redirectToRoute('custom_field_edit', ['id' => $field->getId()]);
            }

            $this->entityManager->remove($field);
            $this->entityManager->flush();

            $this->addFlash('success', $this->translator->trans('custom_field.flash.deleted'));
        }

        return $this->redirectToRoute('custom_field_index', ['entity' => $entityType]);
    }

    #[Route('/{id}/toggle', name: 'custom_field_toggle', methods: ['POST'])]
    public function toggle(Request $request, CustomFieldDefinition $field): Response
    {
        if ($this->isCsrfTokenValid('toggle' . $field->getId(), $request->request->get('_token'))) {
            $field->setIsActive(!$field->isActive());
            $this->entityManager->flush();

            $statusKey = $field->isActive() ? 'activated' : 'deactivated';
            $this->addFlash('success', $this->translator->trans('custom_field.flash.' . $statusKey));
        }

        return $this->redirectToRoute('custom_field_index', [
            'entity' => $field->getEntityType(),
        ]);
    }

    #[Route('/api/reorder', name: 'custom_field_reorder', methods: ['POST'])]
    public function reorder(Request $request): JsonResponse
    {
        /** @var array<string, mixed>|null $data */
        $data = json_decode($request->getContent(), true);

        if (!$this->isCsrfTokenValid('reorder', $data['_token'] ?? '')) {
            return $this->json(['error' => 'Invalid CSRF token'], 403);
        }
        
        if (!isset($data['orderedIds']) || !is_array($data['orderedIds'])) {
            return $this->json(['error' => 'Invalid data'], 400);
        }

        $this->definitionRepository->updateSortOrders($data['orderedIds']);

        return $this->json(['success' => true]);
    }

    #[Route('/api/check-key', name: 'custom_field_check_key', methods: ['GET'])]
    public function checkKey(Request $request): JsonResponse
    {
        $fieldKey = $request->query->get('key');
        $entityType = $request->query->get('entity');
        $excludeId = $request->query->get('exclude');

        if (!$fieldKey || !$entityType) {
            return $this->json(['error' => 'Missing parameters'], 400);
        }

        $exists = $this->definitionRepository->fieldKeyExists(
            $fieldKey, 
            $entityType, 
            $excludeId ? (int) $excludeId : null
        );

        return $this->json([
            'exists' => $exists,
            'available' => !$exists,
        ]);
    }

    #[Route('/preview/{entityType}', name: 'custom_field_preview', methods: ['GET'])]
    public function preview(string $entityType): Response
    {
        $groupedFields = $this->customFieldService->getGroupedFields($entityType);

        return $this->render('custom_field/preview.html.twig', [
            'entityType' => $entityType,
            'groupedFields' => $groupedFields,
        ]);
    }
}
