<?php

namespace App\Controller;

use App\Entity\Company;
use App\Entity\RFQ;
use App\Form\RFQType;
use App\Repository\RFQRepository;
use App\Repository\CompanyRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

#[Route('/rfqs')]
class RFQController extends AbstractController
{
    #[Route('/', name: 'app_rfq_index', methods: ['GET'])]
    public function index(Request $request, RFQRepository $rfqRepository): Response
    {
        // Filters
        $type = $request->query->get('type');
        $status = $request->query->get('status');
        $company = $request->query->get('company');
        $ndaStatus = $request->query->get('nda_status');

        $qb = $rfqRepository->createQueryBuilder('r')
            ->leftJoin('r.company', 'c')
            ->addSelect('c')
            ->orderBy('r.createdAt', 'DESC');

        if ($type) {
            $qb->andWhere('r.type = :type')->setParameter('type', $type);
        }

        if ($status) {
            $qb->andWhere('r.status = :status')->setParameter('status', $status);
        }

        if ($company) {
            $qb->andWhere('r.company = :company')->setParameter('company', $company);
        }

        if ($ndaStatus === 'sent') {
            $qb->andWhere('r.ndaSent = true');
        } elseif ($ndaStatus === 'executed') {
            $qb->andWhere('r.ndaExecuted = true');
        } elseif ($ndaStatus === 'pending') {
            $qb->andWhere('r.ndaSent = false');
        }

        $rfqs = $qb->getQuery()->getResult();

        return $this->render('rfq/index.html.twig', [
            'rfqs' => $rfqs,
        ]);
    }

    #[Route('/pipeline', name: 'app_rfq_pipeline', methods: ['GET'])]
    public function pipeline(Request $request, RFQRepository $rfqRepository): Response
    {
        // Get filters
        $type = $request->query->get('type');
        $companyId = $request->query->get('company');

        $qb = $rfqRepository->createQueryBuilder('r')
            ->leftJoin('r.company', 'c')
            ->addSelect('c')
            ->orderBy('r.createdAt', 'DESC');

        if ($type) {
            $qb->andWhere('r.type = :type')->setParameter('type', $type);
        }

        if ($companyId) {
            $qb->andWhere('r.company = :company')->setParameter('company', $companyId);
        }

        $allRfqs = $qb->getQuery()->getResult();

        // Group by status for kanban view
        $pipeline = [
            'Pending' => [],
            'In Review' => [],
            'Submitted' => [],
            'Won' => [],
            'Lost' => [],
        ];

        foreach ($allRfqs as $rfq) {
            $status = $rfq->getStatus();
            if (isset($pipeline[$status])) {
                $pipeline[$status][] = $rfq;
            }
        }

        return $this->render('rfq/pipeline.html.twig', [
            'pipeline' => $pipeline,
        ]);
    }

    #[Route('/new', name: 'app_rfq_new', methods: ['GET', 'POST'])]
    public function new(Request $request, EntityManagerInterface $entityManager): Response
    {
        $rfq = new RFQ();
        
        // Pre-fill company from query parameter
        $companyId = $request->query->get('company');
        if ($companyId) {
            $company = $entityManager->getRepository(Company::class)->find($companyId);
            if ($company) {
                $rfq->setCompany($company);
            }
        }

        $form = $this->createForm(RFQType::class, $rfq);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager->persist($rfq);
            $entityManager->flush();

            $this->addFlash('success', 'RFQ created successfully.');

            return $this->redirectToRoute('app_rfq_show', ['id' => $rfq->getId()]);
        }

        return $this->render('rfq/new.html.twig', [
            'rfq' => $rfq,
            'form' => $form,
        ]);
    }

    #[Route('/{id}', name: 'app_rfq_show', methods: ['GET'])]
    public function show(RFQ $rfq): Response
    {
        return $this->render('rfq/show.html.twig', [
            'rfq' => $rfq,
        ]);
    }

    #[Route('/{id}/edit', name: 'app_rfq_edit', methods: ['GET', 'POST'])]
    public function edit(Request $request, RFQ $rfq, EntityManagerInterface $entityManager): Response
    {
        $form = $this->createForm(RFQType::class, $rfq);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager->flush();

            $this->addFlash('success', 'RFQ updated successfully.');

            return $this->redirectToRoute('app_rfq_show', ['id' => $rfq->getId()]);
        }

        return $this->render('rfq/edit.html.twig', [
            'rfq' => $rfq,
            'form' => $form,
        ]);
    }

    #[Route('/{id}/delete', name: 'app_rfq_delete', methods: ['POST'])]
    public function delete(Request $request, RFQ $rfq, EntityManagerInterface $entityManager): Response
    {
        if ($this->isCsrfTokenValid('delete'.$rfq->getId(), $request->request->get('_token'))) {
            $entityManager->remove($rfq);
            $entityManager->flush();

            $this->addFlash('success', 'RFQ deleted successfully.');
        }

        return $this->redirectToRoute('app_rfq_index');
    }

    #[Route('/{id}/update-status', name: 'app_rfq_update_status', methods: ['POST'])]
    public function updateStatus(Request $request, RFQ $rfq, EntityManagerInterface $entityManager): Response
    {
        $newStatus = $request->request->get('status');
        
        if (in_array($newStatus, ['Pending', 'In Review', 'Submitted', 'Won', 'Lost'])) {
            $rfq->setStatus($newStatus);
            $entityManager->flush();

            $this->addFlash('success', 'RFQ status updated to ' . $newStatus);
        }

        return $this->redirectToRoute('app_rfq_show', ['id' => $rfq->getId()]);
    }

    #[Route('/{id}/nda-sent', name: 'app_rfq_nda_sent', methods: ['POST'])]
    public function ndaSent(Request $request, RFQ $rfq, EntityManagerInterface $entityManager): Response
    {
        if ($this->isCsrfTokenValid('nda_sent'.$rfq->getId(), $request->request->get('_token'))) {
            $rfq->setNdaSent(true);
            $rfq->setNdaDate(new \DateTime());
            $entityManager->flush();

            $this->addFlash('success', 'NDA marked as sent.');
        }

        return $this->redirectToRoute('app_rfq_show', ['id' => $rfq->getId()]);
    }

    #[Route('/{id}/nda-executed', name: 'app_rfq_nda_executed', methods: ['POST'])]
    public function ndaExecuted(Request $request, RFQ $rfq, EntityManagerInterface $entityManager): Response
    {
        if ($this->isCsrfTokenValid('nda_executed'.$rfq->getId(), $request->request->get('_token'))) {
            $rfq->setNdaExecuted(true);
            
            // Set NDA sent and date if not already set
            if (!$rfq->isNdaSent()) {
                $rfq->setNdaSent(true);
                $rfq->setNdaDate(new \DateTime());
            }
            
            $entityManager->flush();

            $this->addFlash('success', 'NDA marked as executed.');
        }

        return $this->redirectToRoute('app_rfq_show', ['id' => $rfq->getId()]);
    }
}
