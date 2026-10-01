<?php

namespace App\Tests\Functional\Regression;

use App\Entity\Company;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Round-9: country/region selection consistency — ONE canonical territory
 * catalog behind both the company FORM and the company FILTER.
 *
 * Contract (data-compatible — stored values are NEVER rewritten):
 *  - the filter offers EVERY canonical territory (no missing regions);
 *  - selecting a canonical territory surfaces rows stored under legacy
 *    coarse codes and vice versa ('morocco' ↔ 'MA');
 *  - rows stored with values outside every vocabulary still display
 *    verbatim and never break the page.
 */
class RegionFilterConsistencyTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);

        $connection = $this->em->getConnection();
        $connection->executeStatement('SET FOREIGN_KEY_CHECKS=0');
        foreach (['companies', 'users'] as $table) {
            $connection->executeStatement('TRUNCATE TABLE ' . $table);
        }
        $connection->executeStatement('SET FOREIGN_KEY_CHECKS=1');

        $user = new User();
        $user->setEmail('region-filter@example.com');
        $user->setFirstName('Reg');
        $user->setLastName('Ion');
        $user->setPassword('x');
        $user->setRoles(['ROLE_ADMIN']);
        $user->setActive(true);
        $this->em->persist($user);

        // The three vocabularies that exist in stored data.
        $this->makeCompany('Legacy MA Co', 'MA');
        $this->makeCompany('Canonical Morocco Co', 'morocco');
        $this->makeCompany('Legacy GB Co', 'GB');
        $this->makeCompany('Canonical UK Co', 'uk');
        $this->em->flush();
        $this->em->clear();
    }

    private function makeCompany(string $name, ?string $region): void
    {
        $company = new Company();
        $company->setName($name);
        $company->setAccountTier('C');
        $company->setRegion($region);
        $this->em->persist($company);
    }

    private function listCompanies(string $region): array
    {
        $this->client->loginUser($this->em->getRepository(User::class)->findOneBy(['email' => 'region-filter@example.com']));
        $crawler = $this->client->request('GET', '/companies?region=' . urlencode($region));
        $this->assertResponseIsSuccessful();

        // The list renders each company as a module card whose title link
        // carries the company name.
        return $crawler->filter('a.rams-module .rams-module__title')->each(
            static fn ($n) => trim((string) $n->text())
        );
    }

    public function testFilterOffersEveryCanonicalTerritory(): void
    {
        $admin = $this->em->getRepository(User::class)->findOneBy(['email' => 'region-filter@example.com']);
        $this->client->loginUser($admin);
        $crawler = $this->client->request('GET', '/companies');
        $this->assertResponseIsSuccessful();

        $options = $crawler->filter('select[name="region"] option')->each(
            static fn ($n) => $n->attr('value')
        );

        foreach (\App\Service\RegionStandardizationService::VALID_REGIONS as $territory) {
            $this->assertContains(
                $territory,
                $options,
                "filter must offer canonical territory '{$territory}' — no missing regions"
            );
        }
    }

    public function testCanonicalSelectionSurfacesLegacyRows(): void
    {
        $names = $this->listCompanies('morocco');
        $this->assertNotEmpty(array_filter($names, fn ($n) => str_contains($n, 'Legacy MA Co')), 'canonical morocco must surface legacy MA rows');
        $this->assertNotEmpty(array_filter($names, fn ($n) => str_contains($n, 'Canonical Morocco Co')));
        $this->assertNotContains('Legacy GB Co', $names, 'no cross-territory leakage');
    }

    public function testLegacySelectionSurfacesCanonicalRows(): void
    {
        $names = $this->listCompanies('MA');
        $this->assertNotEmpty(array_filter($names, fn ($n) => str_contains($n, 'Canonical Morocco Co')), 'legacy MA selection must surface canonical morocco rows');
        $this->assertNotEmpty(array_filter($names, fn ($n) => str_contains($n, 'Legacy MA Co')));
    }

    public function testUKTerritoryMatchesGBRowsBothWays(): void
    {
        $byCanonical = $this->listCompanies('uk');
        $this->assertNotEmpty(array_filter($byCanonical, fn ($n) => str_contains($n, 'Legacy GB Co')));

        $byLegacy = $this->listCompanies('GB');
        $this->assertNotEmpty(array_filter($byLegacy, fn ($n) => str_contains($n, 'Canonical UK Co')));
    }
}
