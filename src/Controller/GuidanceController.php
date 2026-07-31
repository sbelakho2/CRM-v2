<?php

namespace App\Controller;

use App\Service\GuidanceNotificationService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
class GuidanceController extends AbstractController
{
    public function __construct(
        private GuidanceNotificationService $guidanceService
    ) {}
    #[Route('/guidance/dismiss', name: 'guidance_dismiss', methods: ['POST'])]
    public function dismiss(Request $request): JsonResponse
    {
        if (!$this->isCsrfTokenValid('guidance_dismiss', $request->request->get('_csrf_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $index = $request->request->get('index');
        $session = $request->getSession();
        
        $notifications = $session->get('guidance_notifications', []);
        
        if (isset($notifications[$index])) {
            $dismissedKey = $notifications[$index]['dismissKey'] ?? null;
            
            // Remove from current notifications
            unset($notifications[$index]);
            // Re-index array to maintain sequential keys
            $notifications = array_values($notifications);
            $session->set('guidance_notifications', $notifications);
            
            // Permanently dismiss this notification by storing the dismissKey
            if ($dismissedKey) {
                $dismissedNotifications = $session->get('dismissed_guidance', []);
                $dismissedNotifications[] = $dismissedKey;
                $session->set('dismissed_guidance', $dismissedNotifications);
            }
            
            return new JsonResponse(['success' => true]);
        }
        
        return new JsonResponse(['success' => false], 400);
    }
    
    #[Route('/guidance/dismiss-all', name: 'guidance_dismiss_all', methods: ['POST'])]
    public function dismissAll(Request $request): JsonResponse
    {
        if (!$this->isCsrfTokenValid('guidance_dismiss_all', $request->request->get('_csrf_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $session = $request->getSession();
        
        // Get all notifications and mark their dismissKeys as permanently dismissed
        $notifications = $session->get('guidance_notifications', []);
        $dismissedNotifications = $session->get('dismissed_guidance', []);
        
        foreach ($notifications as $notification) {
            if (isset($notification['dismissKey'])) {
                $dismissedNotifications[] = $notification['dismissKey'];
            }
        }
        
        $session->set('dismissed_guidance', $dismissedNotifications);
        $session->remove('guidance_notifications');
        
        return new JsonResponse(['success' => true]);
    }
    
    #[Route('/guidance/all', name: 'app_guidance_all', methods: ['GET'])]
    public function all(Request $request): Response
    {
        $session = $request->getSession();
        
        // Generate daily workflow reminders if user is logged in
        $user = $this->getUser();
        if ($user && method_exists($user, 'getId')) {
            // Only generate once per day per user
            $lastGenerated = $session->get('guidance_last_generated_' . $user->getId());
            $today = (new \DateTime())->format('Y-m-d');
            
            if ($lastGenerated !== $today) {
                try {
                    $this->guidanceService->dailyWorkflowReminders($user);
                    $this->guidanceService->checkUpcomingRFQDeadlines($user);
                    $this->guidanceService->checkComplianceReminders($user);
                    
                    $session->set('guidance_last_generated_' . $user->getId(), $today);
                } catch (\Exception $e) {
                    // Log error but don't break the page
                    error_log('Guidance generation error: ' . $e->getMessage());
                }
            }
        }
        
        $notifications = $session->get('guidance_notifications', []);
        
        return $this->render('guidance/all.html.twig', [
            'notifications' => $notifications,
        ]);
    }
}
