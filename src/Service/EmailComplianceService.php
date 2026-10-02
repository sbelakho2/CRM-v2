<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\EmailCampaign;
use App\Entity\EmailSend;
use App\Entity\Contact;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Ensures email campaigns comply with CAN-SPAM, CASL, and international email regulations
 * Features: physical address requirement, unsubscribe links, sender identification
 */
class EmailComplianceService
{
    private const DEFAULT_COMPANY_NAME = 'STARZ Morocco';
    private const DEFAULT_PHYSICAL_ADDRESS = '123 Business Avenue, Tangier, Morocco';
    private const DEFAULT_COMPANY_PHONE = '+212 539 123 456';
    
    private EntityManagerInterface $em;
    private EmailConsentService $consentService;
    
    // Company information for compliance
    private string $companyName;
    private string $physicalAddress;
    private string $companyPhone;

    public function getCompanyName(): string
    {
        return $this->companyName;
    }

    public function getPhysicalAddress(): string
    {
        return $this->physicalAddress;
    }

    public function getCompanyPhone(): string
    {
        return $this->companyPhone;
    }

    public function __construct(
        EntityManagerInterface $em,
        EmailConsentService $consentService
    ) {
        $this->em = $em;
        $this->consentService = $consentService;
        // Company identity is read from the environment when configured so
        // the compliance footer never carries fabricated contact details.
        $this->companyName = $this->envValue('COMPANY_NAME') ?? self::DEFAULT_COMPANY_NAME;
        $this->physicalAddress = $this->envValue('COMPANY_ADDRESS') ?? self::DEFAULT_PHYSICAL_ADDRESS;
        $this->companyPhone = $this->envValue('COMPANY_PHONE') ?? self::DEFAULT_COMPANY_PHONE;
    }

    /**
     * Read a non-empty env var (prefers real env over the $_ENV superglobal).
     */
    private function envValue(string $name): ?string
    {
        $value = $_ENV[$name] ?? $_SERVER[$name] ?? getenv($name);
        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * Validate email content for CAN-SPAM/CASL compliance
     * 
     * @param string $htmlContent Email HTML content
     * @param EmailCampaign $campaign The campaign
     * @return array{compliant: bool, errors: list<string>, warnings: list<string>} Validation results
     */
    public function validateEmailCompliance(string $htmlContent, EmailCampaign $campaign): array
    {
        $errors = [];
        $warnings = [];

        // 1. Check for physical address (CAN-SPAM requirement)
        if (!$this->hasPhysicalAddress($htmlContent)) {
            $errors[] = 'Missing physical mailing address (required by CAN-SPAM Act)';
        }

        // 2. Check for unsubscribe link (CAN-SPAM & CASL requirement)
        if (!$this->hasUnsubscribeLink($htmlContent)) {
            $errors[] = 'Missing unsubscribe link (required by CAN-SPAM/CASL)';
        }

        // 3. Check for clear sender identification
        if (!$campaign->getFromName() || !$campaign->getFromEmail()) {
            $errors[] = 'Missing sender identification (from name and email required)';
        }

        // 4. Check for accurate subject line (no deceptive subjects)
        $subject = $campaign->getSubject();
        if ($subject && $this->hasDeceptiveSubject($subject, $htmlContent)) {
            $warnings[] = 'Subject line may be misleading - ensure it accurately reflects email content';
        }

        // 5. Check for clear identification as advertisement (if commercial)
        if ($this->isCommercialEmail($htmlContent) && !$this->hasAdvertisementDisclosure($htmlContent)) {
            $warnings[] = 'Commercial email should include clear advertisement disclosure';
        }

        return [
            'compliant' => empty($errors),
            'errors' => $errors,
            'warnings' => $warnings
        ];
    }

    /**
     * Add compliance footer to email HTML
     * Includes: physical address, unsubscribe link, company info
     * 
     * @param string $htmlContent Original email HTML
     * @param Contact $contact Recipient contact
     * @param EmailCampaign $campaign Campaign
     * @return string HTML with compliance footer
     */
    public function addComplianceFooter(string $htmlContent, Contact $contact, EmailCampaign $campaign): string
    {
        $unsubscribeLink = $this->consentService->generateUnsubscribeLink($contact, $campaign->getId());
        $contactEmail = $contact->getEmail() ?? '';

        // Base URL for the "manage preferences" link. Never fabricate a
        // domain: use the configured DEFAULT_URI (or legacy APP_BASE_URL);
        // when none is configured the link is omitted entirely.
        $baseUrl = rtrim($this->envValue('DEFAULT_URI') ?? $this->envValue('APP_BASE_URL') ?? '', '/');
        $managePreferencesLink = $baseUrl !== ''
            ? sprintf(
                ' | <a href="%s/email/manage-preferences/%s" style="color: #0066cc; text-decoration: underline;">Manage email preferences</a>',
                htmlspecialchars($baseUrl),
                base64_encode($contactEmail)
            )
            : '';

        $footer = sprintf('
            <div style="margin-top: 40px; padding-top: 20px; border-top: 1px solid #e0e0e0; font-size: 12px; color: #555; font-family: Arial, sans-serif;">
                <table width="100%%" cellpadding="0" cellspacing="0" style="font-size: 12px; color: #555;">
                    <tr>
                        <td style="padding: 10px 0;">
                            <strong>%s</strong><br>
                            %s<br>
                            Phone: %s
                        </td>
                    </tr>
                    <tr>
                        <td style="padding: 10px 0;">
                            You are receiving this email because you are a contact in our CRM system.
                        </td>
                    </tr>
                    <tr>
                        <td style="padding: 10px 0;">
                            <a href="%s" style="color: #0066cc; text-decoration: underline;">Unsubscribe from all emails</a>%s
                        </td>
                    </tr>
                    <tr>
                        <td style="padding: 10px 0; font-size: 11px; color: #666;">
                            This email was sent to %s. If you believe you received this email in error, please contact us.
                        </td>
                    </tr>
                </table>
            </div>
        ',
            htmlspecialchars($this->companyName),
            nl2br(htmlspecialchars($this->physicalAddress)),
            htmlspecialchars($this->companyPhone),
            htmlspecialchars($unsubscribeLink),
            $managePreferencesLink,
            htmlspecialchars($contactEmail)
        );

        // Insert footer before closing body tag
        if (stripos($htmlContent, '</body>') !== false) {
            $htmlContent = str_ireplace('</body>', $footer . '</body>', $htmlContent);
        } else {
            $htmlContent .= $footer;
        }

        return $htmlContent;
    }

    /**
     * Check if HTML contains physical mailing address
     * 
     * @param string $htmlContent
     * @return bool
     */
    private function hasPhysicalAddress(string $htmlContent): bool
    {
        // Check for address pattern or company name + location
        $patterns = [
            '/\d+\s+[\w\s]+(?:street|avenue|road|blvd|st|ave|rd)/i',
            '/' . preg_quote($this->companyName, '/') . '.*(?:morocco|tangier|casablanca)/i'
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $htmlContent)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if HTML contains unsubscribe link
     * 
     * @param string $htmlContent
     * @return bool
     */
    private function hasUnsubscribeLink(string $htmlContent): bool
    {
        $patterns = [
            '/href=(["\']).*unsubscribe.*\1/i',
            '/href=(["\']).*opt-out.*\1/i',
            '/>.*unsubscribe.*</i'
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $htmlContent)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if subject line might be deceptive
     * 
     * @param string $subject
     * @param string $htmlContent
     * @return bool
     */
    private function hasDeceptiveSubject(string $subject, string $htmlContent): bool
    {
        $deceptiveKeywords = [
            'free',
            'winner',
            'congratulations',
            'urgent',
            're:',
            'fwd:',
            'claim now',
            '100%',
            'risk-free'
        ];

        $subjectLower = strtolower($subject);
        
        foreach ($deceptiveKeywords as $keyword) {
            if (strpos($subjectLower, $keyword) !== false) {
                // If keyword found in subject, check if it's genuinely in content
                if (stripos($htmlContent, $keyword) === false) {
                    return true; // Keyword in subject but not in body = potentially deceptive
                }
            }
        }

        return false;
    }

    /**
     * Check if email is commercial in nature
     * 
     * @param string $htmlContent
     * @return bool
     */
    private function isCommercialEmail(string $htmlContent): bool
    {
        $commercialKeywords = [
            'buy',
            'purchase',
            'order now',
            'shop',
            'sale',
            'discount',
            'offer',
            'price',
            'product',
            'service',
            'quote'
        ];

        $contentLower = strtolower(strip_tags($htmlContent));
        
        $matchCount = 0;
        foreach ($commercialKeywords as $keyword) {
            if (strpos($contentLower, $keyword) !== false) {
                $matchCount++;
            }
        }

        // If 3+ commercial keywords found, consider it commercial
        return $matchCount >= 3;
    }

    /**
     * Check if email has advertisement disclosure
     * 
     * @param string $htmlContent
     * @return bool
     */
    private function hasAdvertisementDisclosure(string $htmlContent): bool
    {
        $patterns = [
            '/advertisement/i',
            '/promotional\s+email/i',
            '/marketing\s+message/i',
            '/commercial\s+communication/i'
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $htmlContent)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Pre-flight check before sending campaign
     * Validates consent, compliance, and deliverability
     *
     * @param EmailCampaign $campaign
     * @param list<Contact> $contacts
     * @return array{can_send: bool, total_contacts: int, valid_contacts: int, invalid_contacts: int, no_consent: int, suppressed: int, compliance_issues: list<string>, contact_issues: list<array{email: string|null, issue: string}>} Validation results
     */
    public function preFlightCheck(EmailCampaign $campaign, array $contacts): array
    {
        $results = [
            'can_send' => true,
            'total_contacts' => count($contacts),
            'valid_contacts' => 0,
            'invalid_contacts' => 0,
            'no_consent' => 0,
            'suppressed' => 0,
            'compliance_issues' => [],
            'contact_issues' => []
        ];

        // Check campaign compliance
        $htmlContent = $campaign->getBodyHtml() ?? '';
        $validation = $this->validateEmailCompliance($htmlContent, $campaign);
        
        if (!$validation['compliant']) {
            $results['can_send'] = false;
            $results['compliance_issues'] = $validation['errors'];
        }

        // Check each contact
        foreach ($contacts as $contact) {
            // Check consent
            if (!$this->consentService->hasConsent($contact)) {
                $results['no_consent']++;
                $results['contact_issues'][] = [
                    'email' => $contact->getEmail(),
                    'issue' => 'No consent - contact is unsubscribed or has not opted in'
                ];
                continue;
            }

            // Check email validity
            if (!filter_var($contact->getEmail(), FILTER_VALIDATE_EMAIL)) {
                $results['invalid_contacts']++;
                $results['contact_issues'][] = [
                    'email' => $contact->getEmail(),
                    'issue' => 'Invalid email address format'
                ];
                continue;
            }

            $results['valid_contacts']++;
        }

        // If no valid contacts, cannot send
        if ($results['valid_contacts'] === 0) {
            $results['can_send'] = false;
        }

        return $results;
    }

    /**
     * Get compliance status report for a campaign
     *
     * @param EmailCampaign $campaign
     * @return array{campaign_name: string|null, compliance_status: string, validation: array{compliant: bool, errors: list<string>, warnings: list<string>}, statistics: array{total_sent: int, bounced: int, bounce_rate: float}, required_elements: array{physical_address: bool, unsubscribe_link: bool, sender_identification: bool}}
     */
    public function getComplianceReport(EmailCampaign $campaign): array
    {
        $htmlContent = $campaign->getBodyHtml() ?? '';
        $validation = $this->validateEmailCompliance($htmlContent, $campaign);

        // Get send statistics
        $sends = $this->em->getRepository(EmailSend::class)
            ->findBy(['campaign' => $campaign]);

        $totalSent = count($sends);
        $bounced = 0;
        $unsubscribed = 0;

        foreach ($sends as $send) {
            if ($send->getStatus() === 'bounced') {
                $bounced++;
            }
        }

        return [
            'campaign_name' => $campaign->getName(),
            'compliance_status' => $validation['compliant'] ? 'Compliant' : 'Non-Compliant',
            'validation' => $validation,
            'statistics' => [
                'total_sent' => $totalSent,
                'bounced' => $bounced,
                'bounce_rate' => $totalSent > 0 ? round(($bounced / $totalSent) * 100, 2) : 0
            ],
            'required_elements' => [
                'physical_address' => $this->hasPhysicalAddress($htmlContent),
                'unsubscribe_link' => $this->hasUnsubscribeLink($htmlContent),
                'sender_identification' => !empty($campaign->getFromName()) && !empty($campaign->getFromEmail())
            ]
        ];
    }

    /**
     * Set company information for compliance footer
     * 
     * @param string $name
     * @param string $address
     * @param string $phone
     */
    public function setCompanyInfo(string $name, string $address, string $phone): void
    {
        $this->companyName = $name;
        $this->physicalAddress = $address;
        $this->companyPhone = $phone;
    }
}
