<?php

namespace App\Controller;

use App\Service\EmailConsentService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Public one-click unsubscribe endpoint.
 *
 * Emails sent by the platform (marketing campaigns and playbook sequences)
 * include an unsubscribe footer linking to this route with a signed token
 * (see EmailConsentService::generateUnsubscribeLink / PlaybookEngine).
 */
#[Route('/email/unsubscribe')]
class EmailUnsubscribeController extends AbstractController
{
    #[Route('', name: 'email_unsubscribe', methods: ['GET', 'POST'])]
    public function unsubscribe(Request $request, EmailConsentService $consentService): Response
    {
        $token = (string) $request->query->get('token', '');
        if ($token === '') {
            $token = (string) $request->request->get('token', '');
        }

        if ($token === '') {
            return $this->render('email_unsubscribe/result.html.twig', [
                'success' => false,
                'message' => 'email_unsubscribe.missing_token',
            ], new Response('', 400));
        }

        $result = $consentService->processUnsubscribeToken($token);

        return $this->render('email_unsubscribe/result.html.twig', [
            'success' => $result['success'],
            'message' => $result['error'] ?? 'email_unsubscribe.success',
            'email' => $result['email'] ?? null,
        ], new Response('', $result['success'] ? 200 : 422));
    }
}
