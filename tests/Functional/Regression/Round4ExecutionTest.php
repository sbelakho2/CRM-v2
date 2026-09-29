<?php

namespace App\Tests\Functional\Regression;

use App\Entity\Company;
use App\Entity\Contact;
use App\Entity\EmailCampaign;
use App\Entity\EmailSend;
use App\Entity\Quote;
use App\Entity\VerifiedCapability;
use App\Entity\VerifiedCertification;
use App\Repository\EmailSendRepository;
use App\Service\CampaignSendResult;
use App\Service\EmailCampaignService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Round-4 regressions: backoff-retryable failures, retry caps, real quote
 * margins, and claimable verified registers.
 */
class Round4ExecutionTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);

        $connection = $this->em->getConnection();
        $connection->executeStatement('SET FOREIGN_KEY_CHECKS=0');
        foreach (['email_sends', 'email_campaigns', 'email_unsubscribe', 'contacts', 'companies', 'verified_capabilities', 'verified_certifications', 'quotes'] as $table) {
            $connection->executeStatement('TRUNCATE TABLE ' . $table);
        }
        $connection->executeStatement('SET FOREIGN_KEY_CHECKS=1');
    }

    public function testFailedTransportAttemptSchedulesBackoffRetry(): void
    {
        [$campaign, $contact] = $this->campaignFixtures();
        $service = static::getContainer()->get(EmailCampaignService::class);

        $failed = new EmailSend();
        $failed->setCampaign($campaign);
        $failed->setContact($contact);
        $failed->setTouchNumber(1);
        $failed->setEmailAddress($contact->getEmail());
        $failed->setStatus(EmailSend::STATUS_FAILED);
        $failed->setFailureReason('smtp refused');
        // Backoff already elapsed → claimable now.
        $failed->setNextAttemptAt((new \DateTime())->modify('-1 minute'));
        $failed->setRetryCount(1);
        $this->em->persist($failed);
        $this->em->flush();
        $id = $failed->getId();
        $this->em->clear();

        $result = $service->sendToContact(
            $this->em->find(EmailCampaign::class, $campaign->getId()),
            $this->em->find(Contact::class, $contact->getId()),
            1
        );

        // Null transport accepts the retry.
        $this->assertSame(CampaignSendResult::SENT, $result->outcome);
        $row = $this->em->getRepository(EmailSend::class)->find($id);
        $this->assertSame(EmailSend::STATUS_SENT, $row->getStatus());
        $this->assertNull($row->getNextAttemptAt(), 'A delivered row clears its backoff marker');
    }

    public function testAttemptsCapMakesFailureTerminal(): void
    {
        [$campaign, $contact] = $this->campaignFixtures();
        $service = static::getContainer()->get(EmailCampaignService::class);

        $exhausted = new EmailSend();
        $exhausted->setCampaign($campaign);
        $exhausted->setContact($contact);
        $exhausted->setTouchNumber(1);
        $exhausted->setEmailAddress($contact->getEmail());
        $exhausted->setStatus(EmailSend::STATUS_FAILED);
        $exhausted->setRetryCount(EmailCampaignService::MAX_SEND_ATTEMPTS);
        $exhausted->setNextAttemptAt((new \DateTime())->modify('-1 minute'));
        $this->em->persist($exhausted);
        $this->em->flush();
        $this->em->clear();

        $result = $service->sendToContact(
            $this->em->find(EmailCampaign::class, $campaign->getId()),
            $this->em->find(Contact::class, $contact->getId()),
            1
        );

        // Cap reached: not claimable anymore.
        $this->assertSame(CampaignSendResult::ALREADY_IN_PROGRESS, $result->outcome);
    }

    public function testQuoteMarginComesFromRealData(): void
    {
        $company = new Company();
        $company->setName('Margin Co');
        $company->setAccountTier('C');
        $company->setSector('Industrial');
        $this->em->persist($company);

        $quote = new Quote();
        $quote->setCompany($company);
        $quote->setTotalCost('1250.00');   // quoted price
        $quote->setEstimatedCost('1000.00'); // internal cost
        $this->em->persist($quote);

        $override = new Quote();
        $override->setCompany($company);
        $override->setTotalCost('999.00');
        $override->setMarginOverridePercent('27.500');
        $this->em->persist($override);

        $unknown = new Quote();
        $unknown->setCompany($company);
        $this->em->persist($unknown);
        $this->em->flush();

        // Computed: (1250-1000)/1250 = 20%
        $this->assertSame(20.0, $quote->getMarginPercent());
        // Explicit override wins.
        $this->assertSame(27.5, $override->getMarginPercent());
        // Genuinely unknown stays null (callers may substitute defaults).
        $this->assertNull($unknown->getMarginPercent());
    }

    public function testVerifiedRegistersAreTheClaimSource(): void
    {
        $cap = new VerifiedCapability();
        $cap->setSite('Tanger');
        $cap->setCapabilityKey('smt');
        $cap->setLabel('Surface-Mount Technology');
        $cap->setStatus(VerifiedCapability::STATUS_VERIFIED);
        $cap->setVerifiedAt(new \DateTime());
        $this->em->persist($cap);

        $expired = new VerifiedCapability();
        $expired->setSite('Tanger');
        $expired->setCapabilityKey('box_build');
        $expired->setLabel('Box Build');
        $expired->setStatus(VerifiedCapability::STATUS_VERIFIED);
        $expired->setVerifiedAt(new \DateTime());
        $expired->setValidUntil((new \DateTime())->modify('-1 day')); // stale
        $this->em->persist($expired);

        $cert = new VerifiedCertification();
        $cert->setSite('Tanger');
        $cert->setStandard('ISO 9001');
        $cert->setStatus('verified');
        $cert->setValidFrom((new \DateTime())->modify('-30 days'));
        $cert->setValidUntil((new \DateTime())->modify('+300 days'));
        $this->em->persist($cert);
        $this->em->flush();

        $caps = $this->em->getRepository(VerifiedCapability::class)->findClaimable();
        $this->assertArrayHasKey('smt', $caps);
        $this->assertArrayNotHasKey('box_build', $caps, 'Expired capabilities are never claimable');

        $certs = $this->em->getRepository(VerifiedCertification::class)->findClaimableStandards();
        $this->assertContains('ISO 9001', $certs);
    }

    /**
     * @return array{0: EmailCampaign, 1: Contact}
     */
    private function campaignFixtures(): array
    {
        $company = new Company();
        $company->setName('R4 Co');
        $company->setAccountTier('C');
        $company->setSector('Industrial');
        $this->em->persist($company);

        $contact = new Contact();
        $contact->setFirstName('R');
        $contact->setLastName('Four');
        $contact->setEmail('r4@example.com');
        $contact->setCompany($company);
        $this->em->persist($contact);

        $campaign = new EmailCampaign();
        $campaign->setName('R4 Campaign');
        $campaign->setLanguage('EN');
        $campaign->setActive(true);
        $this->em->persist($campaign);

        $this->em->flush();

        return [$campaign, $contact];
    }
}
