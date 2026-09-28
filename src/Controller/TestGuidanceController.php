<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
class TestGuidanceController extends AbstractController
{
    #[Route('/test/clear-guidance', name: 'test_clear_guidance', methods: ['POST'])]
    public function clearGuidance(Request $request): Response
    {
        // Test-only helper: never reachable outside dev/test environments.
        if (!in_array($this->getParameter('kernel.environment'), ['dev', 'test'], true)) {
            throw new NotFoundHttpException();
        }

        // State-changing action: require POST and a CSRF token even in
        // dev/test, so the safe pattern is what gets copied into product code.
        if (!$this->isCsrfTokenValid('clear_guidance', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $session = $request->getSession();
        $session->remove('guidance_notifications');
        $session->remove('dismissed_guidance');

        $this->addFlash('success', 'All guidance notifications and dismissals cleared from session!');

        return $this->redirectToRoute('app_dashboard');
    }
}
