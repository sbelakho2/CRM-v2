<?php

namespace App\Controller;

use App\Repository\ContactRepository;
use App\Repository\CompanyRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api')]
#[IsGranted('ROLE_USER')]
class ApiController extends AbstractController
{
    public function __construct(
        private RateLimiterFactory $apiGeneralLimiter
    ) {}

    #[Route('/contacts/by-company/{companyId}', name: 'api_contacts_by_company', methods: ['GET'])]
    public function getContactsByCompany(int $companyId, Request $request, ContactRepository $contactRepository, CompanyRepository $companyRepository): JsonResponse
    {
        $limiter = $this->apiGeneralLimiter->create($this->getUser()?->getUserIdentifier() ?? (string) $request->getClientIp());
        $limit = $limiter->consume();
        if (!$limit->isAccepted()) {
            $retryAfter = $limit->getRetryAfter()->getTimestamp() - time();
            return $this->json(['error' => 'Too many requests. Please try again later.'], 429, [
                'Retry-After' => (string) max(1, $retryAfter),
            ]);
        }

        $company = $companyRepository->find($companyId);
        if (!$company) {
            return $this->json(['error' => 'Company not found'], 404);
        }

        // Archive contract: archived companies (and their archived contacts)
        // are invisible to non-admins — the API route must not bypass the
        // controllers' visibility rules.
        if ($company->isArchived() && !$this->isGranted('ROLE_ADMIN')) {
            return $this->json(['error' => 'Company not found'], 404);
        }

        $contacts = $contactRepository->createQueryBuilder('c')
            ->andWhere('c.company = :company')
            ->andWhere('c.archivedAt IS NULL')
            ->setParameter('company', $company)
            ->orderBy('c.lastName', 'ASC')
            ->getQuery()
            ->getResult();
        
        $data = array_map(function($contact) {
            return [
                'id' => $contact->getId(),
                'firstName' => $contact->getFirstName(),
                'lastName' => $contact->getLastName(),
                'email' => $contact->getEmail(),
                'jobTitle' => $contact->getJobTitle(),
            ];
        }, $contacts);
        
        return $this->json($data);
    }
}
