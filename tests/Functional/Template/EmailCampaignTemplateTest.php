<?php

namespace App\Tests\Functional\Template;

use App\Entity\User;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Email campaign template functional tests.
 *
 * Renders the mail templates through the container's Twig environment with
 * a minimal context and asserts they produce valid, translatable output.
 */
class EmailCampaignTemplateTest extends WebTestCase
{
    public function testVerificationEmailRendersWithTranslatedContent(): void
    {
        self::bootKernel();
        $twig = self::getContainer()->get('twig');

        $user = new User();
        $user->setFirstName('Jane');

        $html = $twig->render('emails/verification.html.twig', [
            'user' => $user,
            'verificationUrl' => 'https://example.com/verify/abc123',
        ]);

        $this->assertStringContainsString('<!DOCTYPE html>', $html);
        $this->assertStringContainsString('Verify your email', $html);
        $this->assertStringContainsString('Hello Jane', $html);
        $this->assertStringContainsString('https://example.com/verify/abc123', $html);
        $this->assertStringContainsString('Verify email', $html);
    }

    public function testPlaybookEmailExtendsBaseEmailLayout(): void
    {
        self::bootKernel();
        $twig = self::getContainer()->get('twig');

        $html = $twig->render('playbook/emails/default.html.twig', [
            'account_name' => 'Acme Corp',
            'timestamp' => new \DateTimeImmutable('2026-01-01 12:00:00'),
            'unsubscribe_url' => 'https://example.com/unsubscribe/xyz',
        ]);

        $this->assertStringContainsString('<!DOCTYPE html>', $html);
        $this->assertStringContainsString('PLAYBOOK ALERT', $html);
        $this->assertStringContainsString('Acme Corp', $html);
        $this->assertStringContainsString('Unsubscribe from all emails', $html);
    }
}
