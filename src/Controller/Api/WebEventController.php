<?php

namespace App\Controller\Api;

use App\Entity\WebEvent;
use App\Service\AbmResolverService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Annotation\Route;

/**
 * API endpoint for tracking web events from frontend
 */
#[Route('/api')]
class WebEventController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private AbmResolverService $abmResolver,
        private RateLimiterFactory $apiGeneralLimiter,
        private \Psr\Log\LoggerInterface $logger,
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
        $token = $request->headers->get('X-CSRF-Token');
        if (!$token) {
            /** @var array<string, mixed>|null $body */
            $body = json_decode($request->getContent(), true);
            $token = is_array($body) ? ($body['_token'] ?? null) : null;
        }
        if (!$this->isCsrfTokenValid('api_web_event', (string) $token)) {
            return new JsonResponse(['error' => 'Invalid CSRF token.'], 403);
        }

        // Rate limit web event ingestion (prevents DB flood via this endpoint)
        $limiter = $this->apiGeneralLimiter->create($this->getUser()?->getUserIdentifier() ?? (string) $request->getClientIp());
        $limit = $limiter->consume();
        if (!$limit->isAccepted()) {
            $retryAfter = $limit->getRetryAfter()->getTimestamp() - time();
            return new JsonResponse(['error' => 'Too many requests. Please try again later.'], 429, [
                'Retry-After' => (string) max(1, $retryAfter),
            ]);
        }

        try {
            /** @var array<string, mixed>|null $data */
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
                $this->logger->error('ABM processing failed', ['error' => $e->getMessage()]);
            }
            
            return new JsonResponse([
                'status' => 'success',
                'event_id' => $webEvent->getId()
            ]);
            
        } catch (\Exception $e) {
            $this->logger->error('WebEvent tracking failed', ['error' => $e->getMessage()]);
            return new JsonResponse([
                'error' => 'Operation failed. Please try again.',
                'message' => 'An unexpected error occurred.'
            ], 500);
        }
    }
    
    /**
     * Get client IP address, accounting for proxies
     */
    private function getClientIp(Request $request): string
    {
        $ipAddress = $request->getClientIp();
        
        // For GDPR compliance, anonymize last octet for IPv4 (/24)
        if (filter_var($ipAddress, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $parts = explode('.', $ipAddress);
            $parts[3] = '0';
            return implode('.', $parts);
        }
        
        // For IPv6, anonymize the last 16 bits (keep the first 7 hex groups)
        if (filter_var($ipAddress, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            $packed = inet_pton($ipAddress);
            $mask = inet_pton('ffff:ffff:ffff:ffff:ffff:ffff:ffff:0000');
            if ($packed !== false && $mask !== false) {
                return inet_ntop($packed & $mask);
            }
            // Fall back to a safe masked form when binary conversion is unavailable
            return '::';
        }
        
        return $ipAddress;
    }
}
