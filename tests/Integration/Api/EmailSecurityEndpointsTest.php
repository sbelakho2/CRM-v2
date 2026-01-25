<?php

namespace App\Tests\Integration\Api;

use App\Entity\Company;
use App\Entity\Contact;
use App\Entity\EmailCampaign;
use App\Entity\EmailSend;
use App\Service\EmailTrackingSigner;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class EmailSecurityEndpointsTest extends WebTestCase
{
    private function createEmailSend(EntityManagerInterface $em): EmailSend
    {
        $company = new Company();
        $company->setName('Email Security Co');
        $em->persist($company);

        $contact = new Contact();
        $contact->setCompany($company);
        $contact->setFirstName('Test');
        $contact->setLastName('Recipient');
        $contact->setEmail('recipient@example.com');
        $em->persist($contact);

        $campaign = new EmailCampaign();
        $campaign->setName('Security Campaign');
        $campaign->setLanguage('EN');
        $campaign->setTouchCount(1);
        $campaign->setActive(true);
        $em->persist($campaign);

        $send = new EmailSend();
        $send->setCampaign($campaign);
        $send->setContact($contact);
        $send->setTouchNumber(1);
        $send->setSentAt(new \DateTime());
        $em->persist($send);

        $em->flush();

        return $send;
    }

    public function testClickTrackingBlocksUnsignedExternalRedirect(): void
    {
        $client = static::createClient();
        $client->followRedirects(false);

        $em = static::getContainer()->get('doctrine')->getManager();
        $send = $this->createEmailSend($em);

        $client->request('GET', sprintf('/email-campaigns/track/%d/click?url=%s', $send->getId(), urlencode('https://evil.example/phish')));

        $this->assertTrue($client->getResponse()->isRedirect());
        $this->assertSame('/', $client->getResponse()->headers->get('Location'));

        $reloaded = $em->getRepository(EmailSend::class)->find($send->getId());
        $this->assertFalse($reloaded->isClicked());
    }

    public function testClickTrackingAllowsSignedExternalRedirect(): void
    {
        $client = static::createClient();
        $client->followRedirects(false);

        $em = static::getContainer()->get('doctrine')->getManager();
        $send = $this->createEmailSend($em);

        /** @var EmailTrackingSigner $signer */
        $signer = static::getContainer()->get(EmailTrackingSigner::class);

        $destination = 'https://example.com/landing';
        $sig = $signer->signClick($send->getId(), $destination);

        $client->request('GET', sprintf(
            '/email-campaigns/track/%d/click?url=%s&sig=%s',
            $send->getId(),
            urlencode($destination),
            urlencode($sig)
        ));

        $this->assertTrue($client->getResponse()->isRedirect());
        $this->assertSame($destination, $client->getResponse()->headers->get('Location'));

        $reloaded = $em->getRepository(EmailSend::class)->find($send->getId());
        $this->assertTrue($reloaded->isClicked());
    }

    public function testWebhookRequiresSecretWhenConfigured(): void
    {
        $_SERVER['EMAIL_WEBHOOK_SECRET'] = 'test-secret';

        $client = static::createClient();
        $client->request(
            'POST',
            '/webhook/email/generic',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['email_send_id' => 1, 'event' => 'opened'])
        );

        $this->assertSame(401, $client->getResponse()->getStatusCode());

        $client->request(
            'POST',
            '/webhook/email/generic',
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X_WEBHOOK_SECRET' => 'test-secret',
            ],
            content: json_encode(['email_send_id' => 999999, 'event' => 'opened'])
        );

        $this->assertNotSame(401, $client->getResponse()->getStatusCode());
    }
}
