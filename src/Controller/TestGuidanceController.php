<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class TestGuidanceController extends AbstractController
{
    #[Route('/test/clear-guidance', name: 'test_clear_guidance')]
    public function clearGuidance(Request $request): Response
    {
        $session = $request->getSession();
        $session->remove('guidance_notifications');
        $session->remove('dismissed_guidance');
        
        $this->addFlash('success', 'All guidance notifications and dismissals cleared from session!');
        
        return $this->redirectToRoute('app_dashboard');
    }
}
