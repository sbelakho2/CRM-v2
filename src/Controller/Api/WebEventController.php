<?php

namespace App\Controller\Api;

use App\Entity\WebEvent;
use App\Service\AbmResolverService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

/**
 * API endpoint for tracking web events from frontend
 */
#[Route('/api')]
class WebEventController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private AbmResolverService $abmResolver
    ) {}

    /**
     * Receive web event tracking data from frontend JavaScript
     * 
     * Expected payload:
     * {
     *   "url": "/products/page",
     *   "event_type": "page_view|click|form_submit|download",
     *   "metadata": {...}
     * }
     */
    #[Route('/web-event', name: 'api_web_event', methods: ['POST'])]
    public function trackEvent(Request $request): JsonResponse
    {
        try {
            $data = json_decode($request->getContent(), true);
            
            if (!$data || !isset($data['url'])) {
                return new JsonResponse(['error' => 'Invalid payload'], 400);
            }
            
            // Create WebEvent entity
            $webEvent = new WebEvent();
            $webEvent->setTimestamp(new \DateTime());
            $webEvent->setIpAddress($this->getClientIp($request));
            $webEvent->setUrl($data['url']);
            $webEvent->setMethod($request->getMethod());
            $webEvent->setStatusCode(200);
            $webEvent->setUserAgent($request->headers->get('User-Agent'));
            $webEvent->setReferer($request->headers->get('Referer'));
            $webEvent->setIsProcessed(false);
            
            $this->entityManager->persist($webEvent);
            $this->entityManager->flush();
            
            // Process asynchronously in background (could be moved to Messenger)
            // For now, process immediately for testing
            try {
                $this->abmResolver->processWebEvent([
                    'ip' => $webEvent->getIpAddress(),
                    'url' => $webEvent->getUrl(),
                    'timestamp' => $webEvent->getTimestamp()->format('Y-m-d H:i:s'),
                    'user_agent' => $webEvent->getUserAgent(),
                    'referer' => $webEvent->getReferer()
                ]);
                
                $webEvent->setIsProcessed(true);
                $webEvent->setProcessedAt(new \DateTime());
                $this->entityManager->flush();
            } catch (\Exception $e) {
                // Log error but don't fail the tracking
                error_log("ABM processing failed: " . $e->getMessage());
            }
            
            return new JsonResponse([
                'status' => 'success',
                'event_id' => $webEvent->getId()
            ]);
            
        } catch (\Exception $e) {
            return new JsonResponse([
                'error' => 'Failed to track event',
                'message' => $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Get client IP address, accounting for proxies
     */
    private function getClientIp(Request $request): string
    {
        $ipAddress = $request->getClientIp();
        
        // For GDPR compliance, anonymize last octet for IPv4
        if (filter_var($ipAddress, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $parts = explode('.', $ipAddress);
            $parts[3] = '0';
            return implode('.', $parts);
        }
        
        // For IPv6, anonymize last 80 bits
        if (filter_var($ipAddress, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            return inet_ntop(inet_pton($ipAddress) & inet_pton('ffff:ffff:ffff:ffff:ffff::'));
        }
        
        return $ipAddress;
    }
}
