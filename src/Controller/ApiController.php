<?php

namespace App\Controller;

use App\Repository\ContactRepository;
use App\Repository\CompanyRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api')]
#[IsGranted('ROLE_USER')]
class ApiController extends AbstractController
{
    #[Route('/contacts/by-company/{companyId}', name: 'api_contacts_by_company', methods: ['GET'])]
    public function getContactsByCompany(int $companyId, ContactRepository $contactRepository, CompanyRepository $companyRepository): JsonResponse
    {
        $company = $companyRepository->find($companyId);
        if (!$company) {
            return $this->json(['error' => 'Company not found'], 404);
        }

        $contacts = $contactRepository->findBy(['company' => $companyId]);
        
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
