<?php

namespace App\Tests\Unit\Service;

use App\Entity\EmailCampaign;
use App\Entity\Contact;
use App\Service\EmailComplianceService;
use App\Service\EmailConsentService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

class EmailComplianceServiceTest extends TestCase
{
    private EmailComplianceService $service;
    private EntityManagerInterface $em;
    private EmailConsentService $consentService;

    protected function setUp(): void
    {
        $this->em = $this->createMock(EntityManagerInterface::class);
        $this->consentService = $this->createMock(EmailConsentService::class);
        
        $this->service = new EmailComplianceService($this->em, $this->consentService);
    }

    public function testValidateCompliantEmail(): void
    {
        $campaign = new EmailCampaign();
        $campaign->setName('Test Campaign');
        $campaign->setFromName('STARZ Team');
        $campaign->setFromEmail('team@starz.com');
        $campaign->setSubject('Product Update');

        $html = '<p>Hello!</p>
            <p>123 Business Avenue, Tangier, Morocco</p>
            <a href="https://example.com/unsubscribe">Unsubscribe</a>';

        $result = $this->service->validateEmailCompliance($html, $campaign);

        $this->assertTrue($result['compliant']);
        $this->assertEmpty($result['errors']);
    }

    public function testValidateEmailMissingPhysicalAddress(): void
    {
        $campaign = new EmailCampaign();
        $campaign->setName('Test');
        $campaign->setFromName('Team');
        $campaign->setFromEmail('team@starz.com');

        $html = '<p>Hello!</p><a href="/unsubscribe">Unsubscribe</a>';

        $result = $this->service->validateEmailCompliance($html, $campaign);

        $this->assertFalse($result['compliant']);
        $this->assertContains('Missing physical mailing address (required by CAN-SPAM Act)', $result['errors']);
    }

    public function testValidateEmailMissingUnsubscribeLink(): void
    {
        $campaign = new EmailCampaign();
        $campaign->setName('Test');
        $campaign->setFromName('Team');
        $campaign->setFromEmail('team@starz.com');

        $html = '<p>Hello!</p><p>123 Main Street, City</p>';

        $result = $this->service->validateEmailCompliance($html, $campaign);

        $this->assertFalse($result['compliant']);
        $this->assertContains('Missing unsubscribe link (required by CAN-SPAM/CASL)', $result['errors']);
    }

    public function testValidateEmailMissingSenderIdentification(): void
    {
        $campaign = new EmailCampaign();
        $campaign->setName('Test');
        // Not setting fromName and fromEmail

        $html = '<p>Hello!</p><p>123 Main Street</p><a href="/unsubscribe">Unsubscribe</a>';

        $result = $this->service->validateEmailCompliance($html, $campaign);

        $this->assertFalse($result['compliant']);
        $this->assertContains('Missing sender identification (from name and email required)', $result['errors']);
    }

    public function testValidateEmailDeceptiveSubject(): void
    {
        $campaign = new EmailCampaign();
        $campaign->setName('Test');
        $campaign->setFromName('Team');
        $campaign->setFromEmail('team@starz.com');
        $campaign->setSubject('You are a winner! Congratulations!');

        $html = '<p>Product catalog enclosed.</p><p>123 Main Street</p><a href="/unsubscribe">Unsubscribe</a>';

        $result = $this->service->validateEmailCompliance($html, $campaign);

        $this->assertNotEmpty($result['warnings']);
    }

    public function testAddComplianceFooter(): void
    {
        $campaign = new EmailCampaign();
        $campaign->setName('Test');
        
        // Use reflection to set ID for campaign
        $ref = new \ReflectionClass($campaign);
        $idProp = $ref->getProperty('id');
        $idProp->setValue($campaign, 1);

        $contact = $this->createMock(Contact::class);
        $contact->method('getEmail')->willReturn('test@example.com');

        $this->consentService
            ->method('generateUnsubscribeLink')
            ->willReturn('https://crm.example.com/unsubscribe?token=abc123');

        $html = '<html><body><p>Content</p></body></html>';
        $result = $this->service->addComplianceFooter($html, $contact, $campaign);

        // Should contain unsubscribe link
        $this->assertStringContainsString('Unsubscribe from all emails', $result);
        // Should contain company info
        $this->assertStringContainsString('STARZ Morocco', $result);
        // Should contain physical address
        $this->assertStringContainsString('Business Avenue', $result);
        // Should contain recipient email
        $this->assertStringContainsString('test@example.com', $result);
        // Should be inserted before </body>
        $this->assertStringContainsString('</body>', $result);
    }

    public function testAddComplianceFooterWithoutBodyTag(): void
    {
        $campaign = new EmailCampaign();
        $contact = $this->createMock(Contact::class);
        $contact->method('getEmail')->willReturn('test@example.com');

        $this->consentService
            ->method('generateUnsubscribeLink')
            ->willReturn('https://crm.example.com/unsubscribe');

        $html = '<p>Simple content</p>';
        $result = $this->service->addComplianceFooter($html, $contact, $campaign);

        // Footer should be appended
        $this->assertStringContainsString('<p>Simple content</p>', $result);
        $this->assertStringContainsString('Unsubscribe from all emails', $result);
    }

    public function testSetCompanyInfo(): void
    {
        $this->service->setCompanyInfo('Acme Corp', '456 Oak Ave, NYC', '+1 555 1234');

        $campaign = new EmailCampaign();
        $contact = $this->createMock(Contact::class);
        $contact->method('getEmail')->willReturn('test@example.com');

        $this->consentService
            ->method('generateUnsubscribeLink')
            ->willReturn('https://example.com/unsubscribe');

        $html = '<html><body><p>Test</p></body></html>';
        $result = $this->service->addComplianceFooter($html, $contact, $campaign);

        $this->assertStringContainsString('Acme Corp', $result);
        $this->assertStringContainsString('456 Oak Ave, NYC', $result);
        $this->assertStringContainsString('+1 555 1234', $result);
    }

    public function testPreFlightCheckWithNoContacts(): void
    {
        $campaign = new EmailCampaign();
        $campaign->setBodyHtml('<p>Content with 123 Main Street</p><a href="/unsubscribe">Unsubscribe</a>');
        $campaign->setFromName('Team');
        $campaign->setFromEmail('team@example.com');

        $result = $this->service->preFlightCheck($campaign, []);

        $this->assertFalse($result['can_send']);
        $this->assertEquals(0, $result['total_contacts']);
        $this->assertEquals(0, $result['valid_contacts']);
    }

    public function testPreFlightCheckWithValidContacts(): void
    {
        $campaign = new EmailCampaign();
        $campaign->setBodyHtml('<p>Content with 123 Main Street</p><a href="/unsubscribe">Unsubscribe</a>');
        $campaign->setFromName('Team');
        $campaign->setFromEmail('team@example.com');

        $contact = $this->createMock(Contact::class);
        $contact->method('getEmail')->willReturn('valid@example.com');

        $this->consentService->method('hasConsent')->willReturn(true);

        $result = $this->service->preFlightCheck($campaign, [$contact]);

        $this->assertEquals(1, $result['valid_contacts']);
    }

    public function testPreFlightCheckWithNoConsent(): void
    {
        $campaign = new EmailCampaign();
        $campaign->setBodyHtml('<p>Content with 123 Main Street</p><a href="/unsubscribe">Unsubscribe</a>');
        $campaign->setFromName('Team');
        $campaign->setFromEmail('team@example.com');

        $contact = $this->createMock(Contact::class);
        $contact->method('getEmail')->willReturn('noconsent@example.com');

        $this->consentService->method('hasConsent')->willReturn(false);

        $result = $this->service->preFlightCheck($campaign, [$contact]);

        $this->assertEquals(1, $result['no_consent']);
        $this->assertEquals(0, $result['valid_contacts']);
    }
}
