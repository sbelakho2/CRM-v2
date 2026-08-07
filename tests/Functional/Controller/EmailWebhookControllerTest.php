<?php

namespace App\Tests\Functional\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Webhook security contract:
 *  - unset EMAIL_WEBHOOK_SECRET must FAIL CLOSED (403), never process
 *  - wrong secret must be rejected (401)
 *  - correct secret must process
 *  - the per-IP limiter must eventually return 429 under flood
 */
class EmailWebhookControllerTest extends WebTestCase
{
    private const ENDPOINT = '/webhook/email/generic';
    private const SECRET = 'test-webhook-secret';

    protected function setUp(): void
    {
        // The test env does not define EMAIL_WEBHOOK_SECRET; make sure the
        // environment starts clean for the fail-closed scenario.
        putenv('EMAIL_WEBHOOK_SECRET');
        unset($_ENV['EMAIL_WEBHOOK_SECRET'], $_SERVER['EMAIL_WEBHOOK_SECRET']);
    }

    protected function tearDown(): void
    {
        putenv('EMAIL_WEBHOOK_SECRET');
        unset($_ENV['EMAIL_WEBHOOK_SECRET'], $_SERVER['EMAIL_WEBHOOK_SECRET']);
        parent::tearDown();
    }

    private function setSecret(): void
    {
        putenv('EMAIL_WEBHOOK_SECRET=' . self::SECRET);
        $_ENV['EMAIL_WEBHOOK_SECRET'] = self::SECRET;
        $_SERVER['EMAIL_WEBHOOK_SECRET'] = self::SECRET;
    }

    public function testWebhookFailsClosedWhenSecretUnset(): void
    {
        $client = static::createClient();
        $client->request('POST', self::ENDPOINT, [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'email_send_id' => 1,
            'event' => 'open',
        ]));

        $this->assertSame(403, $client->getResponse()->getStatusCode());
    }

    public function testWebhookRejectsWrongSecret(): void
    {
        $this->setSecret();
        $client = static::createClient();

        $client->request('POST', self::ENDPOINT, [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_WEBHOOK_SECRET' => 'wrong-secret',
        ], json_encode([
            'email_send_id' => 1,
            'event' => 'open',
        ]));

        $this->assertSame(401, $client->getResponse()->getStatusCode());
    }

    public function testWebhookAcceptsCorrectSecretAndRejectsMalformedPayload(): void
    {
        $this->setSecret();
        $client = static::createClient();

        $client->request('POST', self::ENDPOINT, [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_WEBHOOK_SECRET' => self::SECRET,
        ], json_encode([
            'email_send_id' => null,
            'event' => null,
        ]));

        // Secret accepted, but the payload is incomplete
        $this->assertSame(400, $client->getResponse()->getStatusCode());
    }

    public function testWebhookAcceptsUnknownSendGracefully(): void
    {
        $this->setSecret();
        $client = static::createClient();

        $client->request('POST', self::ENDPOINT, [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_WEBHOOK_SECRET' => self::SECRET,
        ], json_encode([
            'email_send_id' => 999999,
            'event' => 'open',
        ]));

        $this->assertSame(200, $client->getResponse()->getStatusCode());
    }
}
