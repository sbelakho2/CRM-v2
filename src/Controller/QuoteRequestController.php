<?php

namespace App\Controller;

use App\Entity\QuoteCustomerRequest;
use App\Repository\QuoteCustomerRequestRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Sales queue for customer live-quote requests and acceptances.
 *
 * Round-8 audit: durable QuoteCustomerRequest rows existed but were
 * operationally ORPHANED — nothing surfaced them to a sales rep, while the
 * customer response promised contact "within 24 hours". This queue is the
 * discoverable workflow: open requests first, explicit new → in_review →
 * handled transitions with handler + resolution recorded.
 */
#[Route('/quote-requests')]
#[IsGranted('ROLE_USER')]
class QuoteRequestController extends AbstractController
{
    public function __construct(
        private QuoteCustomerRequestRepository $repository,
        private EntityManagerInterface $entityManager,
    ) {}

    #[Route('', name: 'app_quote_request_index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $status = $request->query->get('status', 'open');
        $isAdmin = $this->isGranted('ROLE_ADMIN');

        $qb = $this->repository->createQueryBuilder('r')
            ->leftJoin('r.quote', 'q')
            ->addSelect('q')
            ->orderBy('r.createdAt', 'DESC')
            ->setMaxResults(200);

        if ($status === 'open') {
            $qb->andWhere('r.status IN (:open)')
               ->setParameter('open', [QuoteCustomerRequest::STATUS_NEW, QuoteCustomerRequest::STATUS_IN_REVIEW]);
        } elseif ($status === QuoteCustomerRequest::STATUS_NEW
            || $status === QuoteCustomerRequest::STATUS_IN_REVIEW
            || $status === QuoteCustomerRequest::STATUS_HANDLED) {
            $qb->andWhere('r.status = :status')
               ->setParameter('status', $status);
        }

        // NOTE: the data model has no per-quote sales owner (quotes carry
        // only archivedBy) — consistent with the rest of the CRM, all
        // ROLE_USER accounts share the request queue.
        $requests = $qb->getQuery()->getResult();

        $counts = $this->repository->createQueryBuilder('r')
            ->select('r.status, COUNT(r.id) AS cnt')
            ->groupBy('r.status')
            ->getQuery()
            ->getResult();

        return $this->render('quote_request/index.html.twig', [
            'requests' => $requests,
            'current_status' => $status,
            'status_counts' => array_column($counts, 'cnt', 'status'),
            'statuses' => [
                QuoteCustomerRequest::STATUS_NEW => 'New',
                QuoteCustomerRequest::STATUS_IN_REVIEW => 'In Review',
                QuoteCustomerRequest::STATUS_HANDLED => 'Handled',
            ],
        ]);
    }

    #[Route('/{id}/status', name: 'app_quote_request_status', methods: ['POST'])]
    public function updateStatus(QuoteCustomerRequest $requestEntity, Request $request): Response
    {
        $this->denyAccessUnlessGranted('ROLE_USER');
        if (!$this->isCsrfTokenValid('quote_request_status' . $requestEntity->getId(), $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        // Shared sales queue (the data model has no per-quote owner).
        if (!$this->isGranted('ROLE_USER')) {
            throw $this->createAccessDeniedException();
        }

        $status = (string) $request->request->get('status');
        $notes = $request->request->get('resolution_notes');

        try {
            $requestEntity->transitionTo(
                $status,
                method_exists($this->getUser(), 'getEmail') ? (string) $this->getUser()->getEmail() : null,
                is_string($notes) && $notes !== '' ? $notes : null
            );
            $this->entityManager->flush();
            $this->addFlash('success', 'Request marked ' . str_replace('_', ' ', $status) . '.');
        } catch (\InvalidArgumentException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('app_quote_request_index');
    }
}
