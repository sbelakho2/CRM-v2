<?php

namespace App\Tests\Functional;

use App\Entity\Activity;
use App\Entity\AuditLog;
use App\Entity\Company;
use App\Entity\ComplianceDocument;
use App\Entity\Contact;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Referential integrity: DB-level onDelete clauses must make deletes safe
 * (no raw-SQL shims needed), and the audit listener must resolve Doctrine
 * proxies so relation-loaded entities are audited under their real class.
 */
class DataIntegrityTest extends KernelTestCase
{
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        \App\Tests\Bootstrap\TestDatabaseSchema::createSchema($this->em->getConnection());
        $connection = $this->em->getConnection();
        $connection->executeStatement('SET FOREIGN_KEY_CHECKS=0');
        foreach (['companies', 'contacts', 'activities', 'compliance_documents', 'audit_logs', 'users'] as $table) {
            $connection->executeStatement('TRUNCATE TABLE ' . $table);
        }
        $connection->executeStatement('SET FOREIGN_KEY_CHECKS=1');
    }

    private function company(string $name): Company
    {
        $company = new Company();
        $company->setName($name);
        $company->setAccountTier(Company::TIER_B);
        $company->setSector('Automotive');
        return $company;
    }

    private function contact(Company $company, string $email): Contact
    {
        $contact = new Contact();
        $contact->setFirstName('Jane');
        $contact->setLastName('Doe');
        $contact->setEmail($email);
        $contact->setCompany($company);
        return $contact;
    }

    public function testDeletingCompanyCascadesToContactsAndDocuments(): void
    {
        $company = $this->company('Cascade Co');
        $this->em->persist($company);
        $this->em->persist($this->contact($company, 'cascade@example.com'));
        $this->em->persist($this->contact($company, 'cascade2@example.com'));

        $doc = new ComplianceDocument();
        $doc->setCompany($company);
        $doc->setFileName('cert.pdf');
        $doc->setDocumentType('certificate');
        $doc->setFilePath('/tmp/cert.pdf');
        $this->em->persist($doc);
        $this->em->flush();

        $companyId = $company->getId();
        $this->em->remove($company);
        $this->em->flush();
        $this->em->clear(); // DB-level cascades are invisible to the identity map

        $this->assertNull($this->em->getRepository(Contact::class)->findOneBy(['email' => 'cascade@example.com']), 'Contacts must cascade-delete with the company');
        $this->assertNull($this->em->getRepository(Contact::class)->findOneBy(['email' => 'cascade2@example.com']));
        $this->assertCount(0, $this->em->getRepository(ComplianceDocument::class)->findBy(['company' => $companyId]), 'Compliance documents must cascade-delete with the company');
    }

    public function testDeletingUserCascadesActivitiesAndNullsAuditLogs(): void
    {
        $user = new User();
        $user->setEmail('cascade-user@example.com');
        $user->setPassword('$2y$13$9UmWR.BDzgbAJEzpkjq9suqeNiIIA6dGpmOe0Em/ClzFAZEIWMOCq');
        $user->setRoles(['ROLE_USER']);
        $user->setFirstName('Cas');
        $user->setLastName('Cade');
        $this->em->persist($user);

        $company = $this->company('User Cascade Co');
        $this->em->persist($company);

        $activity = new Activity();
        $activity->setCompany($company);
        $activity->setUser($user);
        $activity->setType('Call');
        $activity->setSubject('Intro call');
        $activity->setStatus('completed');
        $activity->setActivityDate(new \DateTime());
        $activity->setOutcomeCategory('positive');
        $this->em->persist($activity);
        $this->em->flush();

        $activityId = $activity->getId();
        $this->em->remove($user);
        $this->em->flush();
        $this->em->clear(); // DB-level cascades are invisible to the identity map

        $this->assertNull($this->em->getRepository(Activity::class)->find($activityId), 'Activities must cascade-delete with the user');

        // The user audit log's FK is SET NULL — it must survive the delete
        $auditLogs = $this->em->getRepository(AuditLog::class)->findAll();
        foreach ($auditLogs as $log) {
            $this->assertNull($log->getUser(), 'Audit log user must be SET NULL, not deleted');
        }
    }

    public function testAuditLogRecordsProxyLoadedEntitiesWithRealClassName(): void
    {
        $company = $this->company('Proxy Audit Co');
        $this->em->persist($company);
        $contact = $this->contact($company, 'proxy@example.com');
        $this->em->persist($contact);
        $this->em->flush();

        // Clear the identity map so the next load goes through proxies
        $this->em->clear();

        $loadedCompany = $this->em->getRepository(Company::class)->find($company->getId());
        $this->assertNotNull($loadedCompany);

        // Access the contact through the collection: a lazy-load creates a
        // Doctrine proxy for the Contact entity.
        $proxiedContact = $loadedCompany->getContacts()->first();
        $this->assertNotNull($proxiedContact);
        $proxiedContact->setLastName('Updated-Through-Proxy');
        $this->em->flush();

        // The update must have been audited under the entity's real name —
        // the pre-fix listener silently skipped entities loaded as proxies.
        $logs = $this->em->getRepository(AuditLog::class)->findBy(
            ['entityType' => 'Contact', 'entityId' => $contact->getId()],
            ['createdAt' => 'ASC']
        );

        $this->assertNotEmpty($logs, 'The proxied contact update must be audited (proxy class must not block auditing)');
        $this->assertCount(2, $logs, 'Create + update audit entries expected');
        $this->assertSame('update', end($logs)->getAction());
        $this->assertSame('Contact', end($logs)->getEntityType());

        // And nothing must be recorded under the proxy class name
        $proxyLogs = $this->em->getRepository(AuditLog::class)->findBy(
            ['entityType' => 'Proxies\\__CG__\\App\\Entity\\Contact']
        );
        $this->assertCount(0, $proxyLogs, 'Proxy class names must never appear in the audit log');
    }
}
