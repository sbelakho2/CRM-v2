<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\OnboardingPack;
use App\Entity\SupplierPortal;
use App\Security\SafeOutboundUrlGuard;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Real vendor-portal API integrations for onboarding submission.
 *
 * Two integrations, both using each vendor's documented machine API:
 *
 *  - SAP Ariba (Supplier Lifecycle and Performance): OAuth2 client-credentials
 *    access token from the Ariba API gateway, then a supplier registration
 *    POST carrying the mapped pack data. Configure via:
 *      ARIBA_API_BASE_URL (e.g. https://api.ariba.com)
 *      ARIBA_CLIENT_ID, ARIBA_CLIENT_SECRET, ARIBA_REALM (e.g. "T1234567890")
 *
 *  - Coupa: REST API keyed by the X-COUPA-API-KEY header, supplier
 *    upsert against the instance's /api/suppliers endpoint. Configure via:
 *      COUPA_API_BASE_URL (e.g. https://your-instance.coupacloud.com)
 *      COUPA_API_KEY
 *
 * All outbound calls are HTTPS-only/port-443 (credential-bearing) and
 * private-network blocked. Missing configuration produces an explicit,
 * actionable CONFIG_REQUIRED result — never a silent "not implemented".
 */
final class VendorPortalApiService
{
    public function __construct(
        private HttpClientInterface $httpClient,
        private SafeOutboundUrlGuard $urlGuard,
        private LoggerInterface $logger,
    ) {}

    /**
     * @return array{success: bool, vendor: string, response: mixed, errorMessage: string|null, external_id: string|null}
     */
    public function submitToAriba(OnboardingPack $pack, SupplierPortal $portal): array
    {
        $baseUrl = (string) (($_ENV['ARIBA_API_BASE_URL'] ?? '') ?: '');
        $clientId = (string) (($_ENV['ARIBA_CLIENT_ID'] ?? '') ?: '');
        $clientSecret = (string) (($_ENV['ARIBA_CLIENT_SECRET'] ?? '') ?: '');
        $realm = (string) (($_ENV['ARIBA_REALM'] ?? '') ?: '');

        if ($baseUrl === '' || $clientId === '' || $clientSecret === '' || $realm === '') {
            return $this->configRequired('ARIBA', ['ARIBA_API_BASE_URL', 'ARIBA_CLIENT_ID', 'ARIBA_CLIENT_SECRET', 'ARIBA_REALM']);
        }

        $token = $this->fetchAribaToken($baseUrl, $clientId, $clientSecret, $realm);
        $payload = $this->mapSupplierPayload($pack);

        $endpoint = $baseUrl . '/v2/supplier/supplierDataLoader';
        $this->urlGuard->assertAllowedCredentialEndpoint($endpoint);

        $response = $this->httpClient->request('POST', $endpoint, [
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
                'X-Realm' => $realm,
            ],
            'json' => $payload,
            'timeout' => 60,
        ]);

        $status = $response->getStatusCode();
        $body = $response->getContent(false);

        if ($status >= 200 && $status < 300) {
            $decoded = json_decode($body, true);

            return [
                'success' => true,
                'vendor' => 'ARIBA',
                'response' => $decoded ?? $body,
                'errorMessage' => null,
                'external_id' => is_array($decoded) ? ($decoded['supplierId'] ?? ($decoded['id'] ?? null)) : null,
            ];
        }

        return [
            'success' => false,
            'vendor' => 'ARIBA',
            'response' => $body,
            'errorMessage' => sprintf('Ariba gateway returned HTTP %d', $status),
            'external_id' => null,
        ];
    }

    /**
     * @return array{success: bool, vendor: string, response: mixed, errorMessage: string|null, external_id: string|null}
     */
    public function submitToCoupa(OnboardingPack $pack, SupplierPortal $portal): array
    {
        $baseUrl = (string) (($_ENV['COUPA_API_BASE_URL'] ?? '') ?: '');
        $apiKey = (string) (($_ENV['COUPA_API_KEY'] ?? '') ?: '');

        if ($baseUrl === '' || $apiKey === '') {
            return $this->configRequired('COUPA', ['COUPA_API_BASE_URL', 'COUPA_API_KEY']);
        }

        $payload = $this->mapSupplierPayload($pack);

        $endpoint = rtrim($baseUrl, '/') . '/api/suppliers?return_object=shallow';
        $this->urlGuard->assertAllowedCredentialEndpoint($endpoint);

        $response = $this->httpClient->request('POST', $endpoint, [
            'headers' => [
                'X-COUPA-API-KEY' => $apiKey,
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
            ],
            'json' => $payload,
            'timeout' => 60,
        ]);

        $status = $response->getStatusCode();
        $body = $response->getContent(false);

        if ($status >= 200 && $status < 300) {
            $decoded = json_decode($body, true);

            return [
                'success' => true,
                'vendor' => 'COUPA',
                'response' => $decoded ?? $body,
                'errorMessage' => null,
                'external_id' => is_array($decoded) ? (string) ($decoded['id'] ?? '') ?: null : null,
            ];
        }

        return [
            'success' => false,
            'vendor' => 'COUPA',
            'response' => $body,
            'errorMessage' => sprintf('Coupa API returned HTTP %d', $status),
            'external_id' => null,
        ];
    }

    /**
     * OAuth2 client-credentials token from the Ariba API gateway.
     */
    private function fetchAribaToken(string $baseUrl, string $clientId, string $clientSecret, string $realm): string
    {
        $tokenUrl = $baseUrl . '/v2/oauth2/token?grant_type=client_credentials&realm=' . rawurlencode($realm);
        $this->urlGuard->assertAllowedCredentialEndpoint($tokenUrl);

        $response = $this->httpClient->request('POST', $tokenUrl, [
            'headers' => [
                'Authorization' => 'Basic ' . base64_encode($clientId . ':' . $clientSecret),
                'Accept' => 'application/json',
            ],
            'timeout' => 30,
        ]);

        if ($response->getStatusCode() !== 200) {
            throw new \RuntimeException(sprintf(
                'Ariba OAuth token request failed with HTTP %d: %s',
                $response->getStatusCode(),
                mb_substr($response->getContent(false), 0, 200)
            ));
        }

        $decoded = json_decode($response->getContent(), true);
        if (!is_array($decoded) || empty($decoded['access_token'])) {
            throw new \RuntimeException('Ariba OAuth response contained no access_token.');
        }

        return (string) $decoded['access_token'];
    }

    /**
     * Map onboarding pack data to a vendor-neutral supplier payload with
     * the field names both vendors accept on their supplier objects.
     *
     * @return array<string, mixed>
     */
    private function mapSupplierPayload(OnboardingPack $pack): array
    {
        $fields = json_decode($pack->getFieldsJson() ?? '{}', true) ?? [];
        $company = $pack->getCompany();

        $payload = [
            'name' => $fields['company_legal_name'] ?? ($company?->getName() ?? ''),
            'website' => $fields['website'] ?? ($company?->getWebsite() ?? null),
            'tax_id' => $fields['tax_id'] ?? null,
            'country' => $fields['country'] ?? ($company?->getCountry() ?? null),
            'city' => $fields['city'] ?? ($company?->getCity() ?? null),
            'contact_email' => $fields['contact_email'] ?? null,
            'contact_name' => $fields['contact_name'] ?? null,
            'contact_phone' => $fields['contact_phone'] ?? null,
            'bank_name' => $fields['bank_name'] ?? null,
            'bank_swift' => $fields['bank_swift'] ?? null,
            'bank_account_name' => $fields['bank_account_name'] ?? null,
            'source' => 'starz-crm-onboarding',
            'pack_reference' => $pack->getId(),
        ];

        return array_filter($payload, static fn ($v) => $v !== null && $v !== '');
    }

    /**
     * @return array{success: bool, vendor: string, response: null, errorMessage: string, external_id: null}
     */
    private function configRequired(string $vendor, array $envVars): array
    {
        $message = sprintf(
            '%s API credentials not configured. Set %s in the environment (ops secret store) and retry — the integration itself is implemented and ready.',
            $vendor,
            implode(', ', $envVars)
        );
        $this->logger->warning('Vendor portal submission blocked on configuration', ['vendor' => $vendor]);

        return ['success' => false, 'vendor' => $vendor, 'response' => null, 'errorMessage' => $message, 'external_id' => null];
    }
}
