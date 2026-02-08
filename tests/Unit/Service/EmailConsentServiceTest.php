<?php

namespace App\Tests\Unit\Service;

use App\Service\EmailConsentService;
use App\Entity\Contact;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use PHPUnit\Framework\TestCase;

class EmailConsentServiceTest extends TestCase
{
    private EmailConsentService $service;
    private EntityManagerInterface $em;
    private LoggerInterface $logger;

    protected function setUp(): void
    {
        $this->em = $this->createMock(EntityManagerInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        
        $this->service = new EmailConsentService($this->em, $this->logger);
    }

    public function testRequestDoubleOptInGeneratesHmacToken(): void
    {
        $contact = $this->createMock(Contact::class);
        $contact->method('getEmail')->willReturn('test@example.com');
        
        $result = $this->service->requestDoubleOptIn($contact);
        
        $this->assertArrayHasKey('token', $result);
        $this->assertArrayHasKey('confirm_url', $result);
        $this->assertArrayHasKey('expires_at', $result);
        $this->assertNotEmpty($result['token']);
        $this->assertStringContainsString('/email/confirm-subscription/', $result['confirm_url']);
        $this->assertInstanceOf(\DateTimeInterface::class, $result['expires_at']);
    }

    public function testConfirmOptInWithValidToken(): void
    {
        // Generate a token via requestDoubleOptIn first
        $contact = $this->createMock(Contact::class);
        $contact->method('getEmail')->willReturn('test@example.com');
        
        $request = $this->service->requestDoubleOptIn($contact);
        $token = $request['token'];
        
        // Decode and verify the token structure
        $decoded = base64_decode($token);
        $parts = explode('|', $decoded);
        $this->assertCount(3, $parts, 'Token should have 3 parts: email|timestamp|hmac');
        $this->assertEquals('test@example.com', $parts[0]);
        $this->assertIsNumeric($parts[1]);
        $this->assertNotEmpty($parts[2]); // HMAC
    }

    public function testConfirmOptInWithInvalidToken(): void
    {
        $result = $this->service->confirmOptIn('invalid-token');
        
        $this->assertFalse($result['success']);
        $this->assertEquals('invalid_token', $result['error']);
    }

    public function testConfirmOptInWithTamperedToken(): void
    {
        // Create a token with wrong HMAC
        $payload = 'test@example.com|' . time() . '|wronghmac';
        $token = base64_encode($payload);
        
        $result = $this->service->confirmOptIn($token);
        
        $this->assertFalse($result['success']);
        $this->assertEquals('invalid_token', $result['error']);
    }

    public function testConfirmOptInWithExpiredToken(): void
    {
        // Create a token from 8 days ago (beyond 7-day expiry)
        $oldTimestamp = time() - (8 * 24 * 60 * 60);
        $email = 'test@example.com';
        $payload = $email . '|' . $oldTimestamp;
        $secret = $_ENV['APP_SECRET'] ?? $_SERVER['APP_SECRET'] ?? 'email-consent-fallback-secret';
        $hmac = hash_hmac('sha256', $payload, $secret);
        $token = base64_encode($payload . '|' . $hmac);
        
        $result = $this->service->confirmOptIn($token);
        
        $this->assertFalse($result['success']);
        $this->assertEquals('token_expired', $result['error']);
    }

    public function testGenerateUnsubscribeLinkReturnsSignedUrl(): void
    {
        $contact = $this->createMock(Contact::class);
        $contact->method('getEmail')->willReturn('user@example.com');
        
        $link = $this->service->generateUnsubscribeLink($contact, 42);
        
        $this->assertIsString($link);
        $this->assertStringContainsString('unsubscribe', $link);
        $this->assertStringContainsString('token=', $link);
    }

    public function testProcessUnsubscribeTokenWithValidToken(): void
    {
        // Generate a valid unsubscribe token
        $email = 'user@example.com';
        $timestamp = time();
        $secret = $_ENV['APP_SECRET'] ?? $_SERVER['APP_SECRET'] ?? 'email-consent-fallback-secret';
        $payload = $email . '|' . $timestamp;
        $hmac = hash_hmac('sha256', $payload, $secret);
        $token = base64_encode($payload . '|' . $hmac);
        
        // Create a real contact for the unsubscribe flow
        $contact = new Contact();
        $contact->setFirstName('Test');
        $contact->setLastName('User');
        $contact->setEmail($email);
        
        // Mock repos: Contact repo returns our contact, Unsubscribe repo returns null (not already unsubscribed)
        $contactRepo = $this->createMock(\Doctrine\ORM\EntityRepository::class);
        $contactRepo->method('findOneBy')->willReturn($contact);
        
        $unsubRepo = $this->createMock(\Doctrine\ORM\EntityRepository::class);
        $unsubRepo->method('findOneBy')->willReturn(null);
        
        $this->em->method('getRepository')->willReturnCallback(function ($class) use ($contactRepo, $unsubRepo) {
            if ($class === \App\Entity\EmailUnsubscribe::class) {
                return $unsubRepo;
            }
            return $contactRepo;
        });
        
        $this->em->expects($this->once())->method('persist');
        $this->em->expects($this->once())->method('flush');
        
        $result = $this->service->processUnsubscribeToken($token);
        
        $this->assertTrue($result['success']);
        $this->assertEquals($email, $result['email']);
    }

    public function testProcessUnsubscribeTokenWithInvalidToken(): void
    {
        $result = $this->service->processUnsubscribeToken('invalid');
        
        $this->assertFalse($result['success']);
        $this->assertEquals('Invalid unsubscribe token', $result['error']);
    }

    public function testProcessUnsubscribeTokenWithTamperedHmac(): void
    {
        $payload = 'user@example.com|' . time() . '|tampered-hmac';
        $token = base64_encode($payload);
        
        $result = $this->service->processUnsubscribeToken($token);
        
        $this->assertFalse($result['success']);
        $this->assertEquals('Invalid unsubscribe token', $result['error']);
    }

    public function testTokenConsistencyBetweenRequestAndConfirm(): void
    {
        // The token format from requestDoubleOptIn must be compatible with confirmOptIn
        $contact = new Contact();
        $contact->setFirstName('Test');
        $contact->setLastName('User');
        $contact->setEmail('consistent@example.com');
        
        $request = $this->service->requestDoubleOptIn($contact);
        $token = $request['token'];
        
        // Set up mock repos
        $contactRepo = $this->createMock(\Doctrine\ORM\EntityRepository::class);
        $contactRepo->method('findOneBy')->willReturn($contact);
        
        $unsubRepo = $this->createMock(\Doctrine\ORM\EntityRepository::class);
        $unsubRepo->method('findOneBy')->willReturn(null);
        
        $this->em->method('getRepository')->willReturnCallback(function ($class) use ($contactRepo, $unsubRepo) {
            if ($class === \App\Entity\EmailUnsubscribe::class) {
                return $unsubRepo;
            }
            return $contactRepo;
        });
        
        $result = $this->service->confirmOptIn($token);
        
        // Should not fail with invalid_token or token_expired
        $this->assertNotEquals('invalid_token', $result['error'] ?? null);
        $this->assertNotEquals('token_expired', $result['error'] ?? null);
        $this->assertTrue($result['success']);
    }
}
