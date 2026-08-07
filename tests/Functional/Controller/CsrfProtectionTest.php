<?php

namespace App\Tests\Functional\Controller;

use App\Entity\Company;
use App\Entity\Quote;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * CSRF enforcement: state-changing POST endpoints must reject requests
 * without a valid token, and accept them with one. A representative set
 * of the ~30 endpoints that previously had no CSRF protection.
 */
class CsrfProtectionTest extends WebTestCase
{
    private $client;
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        \App\Tests\Bootstrap\TestDatabaseSchema::createSchema($this->entityManager->getConnection());

        $user = new User();
        $user->setEmail('csrf@example.com');
        $user->setPassword('$2y$13$9UmWR.BDzgbAJEzpkjq9suqeNiIIA6dGpmOe0Em/ClzFAZEIWMOCq');
        $user->setRoles(['ROLE_USER']);
        $user->setFirstName('Csrf');
        $user->setLastName('Tester');
        $this->entityManager->persist($user);
        $this->entityManager->flush();

        $this->client->loginUser($user);
    }

    public function testWebcrawlerDiscoverRejectsMissingToken(): void
    {
        $this->client->request('POST', '/webcrawler/discover', ['sector' => 'Automotive', 'location' => 'Casablanca']);

        $this->assertSame(403, $this->client->getResponse()->getStatusCode());
        $this->assertStringContainsString('CSRF', $this->client->getResponse()->getContent());
    }

    private function csrfToken(string $tokenId): string
    {
        // The CSRF token manager stores tokens in the session; push a fresh
        // request with a session onto the stack to make it usable outside a
        // normal request cycle.
        $container = $this->client->getContainer();
        $session = $container->get('session.factory')->createSession();
        $request = new \Symfony\Component\HttpFoundation\Request();
        $request->setSession($session);
        $container->get('request_stack')->push($request);
        try {
            return $container->get('security.csrf.token_manager')->getToken($tokenId)->getValue();
        } finally {
            $container->get('request_stack')->pop();
        }
    }

    private function createQuote(): Quote
    {
        $company = new Company();
        $company->setName('Csrf Quote Co');
        $company->setAccountTier(Company::TIER_B);
        $company->setSector('Automotive');
        $this->entityManager->persist($company);

        $quote = new Quote();
        $quote->setCompany($company);
        $quote->setQuoteNumber('CSRF-Q-' . uniqid());
        $quote->setStatus('draft');
        $quote->setTotalCost('125.50');
        $quote->setCurrency('USD');
        $this->entityManager->persist($quote);
        $this->entityManager->flush();

        return $quote;
    }

    public function testWebcrawlerDiscoverAcceptsValidToken(): void
    {
        // The token must come from the client's own session: render the page
        // and reuse the token it embeds.
        $crawler = $this->client->request('GET', '/webcrawler');
        $this->assertResponseIsSuccessful();
        $token = $crawler->filter('input[name="_token"]')->first()->attr('value');
        $this->assertNotEmpty($token);

        $this->client->request('POST', '/webcrawler/discover', [
            'sector' => 'Automotive',
            'location' => 'Casablanca',
            '_token' => $token,
        ]);

        // CSRF gate passed: the endpoint now proceeds (any other result is fine)
        $this->assertNotSame(403, $this->client->getResponse()->getStatusCode());
    }

    public function testQuoteReviewRepriceLineRejectsMissingToken(): void
    {
        $this->client->request('POST', '/quote-review/line/1/reprice', [], [], ['CONTENT_TYPE' => 'application/json'], '{}');

        $this->assertSame(403, $this->client->getResponse()->getStatusCode());
    }

    public function testQuoteReviewRepriceLineAcceptsValidToken(): void
    {
        // The client session must hold the token, so generate it through the
        // token manager inside a request on the client's own session.
        $quote = $this->createQuote();

        $crawler = $this->client->request('GET', '/quote-review/' . $quote->getId());
        $this->assertResponseIsSuccessful();

        $html = $this->client->getResponse()->getContent();
        $this->assertMatchesRegularExpression('/const csrfToken = \'([^\']+)\'/', $html);
        preg_match('/const csrfToken = \'([^\']+)\'/', $html, $m);
        $token = $m[1];

        $this->client->request('POST', '/quote-review/line/1/reprice', [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_CSRF_TOKEN' => $token,
        ], '{}');

        // CSRF gate passed — the line does not exist so the flow must not
        // crash with a CSRF error; a 4xx/5xx processing error is acceptable,
        // 403 is not.
        $this->assertNotSame(403, $this->client->getResponse()->getStatusCode());
    }

    public function testDiscoveryPipelinePreviewRejectsMissingToken(): void
    {
        $this->client->request('POST', '/discovery-pipeline/preview', [], [], ['CONTENT_TYPE' => 'application/json'], '{"sector":"Automotive"}');

        $this->assertSame(403, $this->client->getResponse()->getStatusCode());
    }
}
