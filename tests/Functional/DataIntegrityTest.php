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

    public function testCompanyArchivePreservesChildrenAndDbBlocksHardDelete(): void
    {
        $company = $this->company('Preserve Co');
        $this->em->persist($company);
        $this->em->persist($this->contact($company, 'preserve@example.com'));
        $this->em->persist($this->contact($company, 'preserve2@example.com'));

        $doc = new ComplianceDocument();
        $doc->setCompany($company);
        $doc->setFileName('cert.pdf');
        $doc->setDocumentType('certificate');
        $doc->setDocumentKey('certificate');
        $doc->setFilePath('cert.pdf');
        $this->em->persist($doc);

        $archiver = new User();
        $archiver->setEmail('archiver@example.com');
        $archiver->setPassword('irrelevant-but-not-null');
        $archiver->setRoles(['ROLE_USER']);
        $archiver->setFirstName('Ar');
        $archiver->setLastName('Chiver');
        $this->em->persist($archiver);
        $this->em->flush();

        // The CRM's supported lifecycle: archive, never hard-delete.
        $company->archive($archiver, 'test archive');
        $this->em->flush();
        $this->em->clear();

        $archived = $this->em->getRepository(Company::class)->find($company->getId());
        $this->assertTrue($archived->isArchived());
        $this->assertNotNull($archived->getArchivedAt());
        $this->assertSame('test archive', $archived->getArchiveReason());

        // History survives: contacts and compliance documents are untouched.
        $this->assertNotNull($this->em->getRepository(Contact::class)->findOneBy(['email' => 'preserve@example.com']));
        $this->assertNotNull($this->em->getRepository(Contact::class)->findOneBy(['email' => 'preserve2@example.com']));
        $this->assertCount(1, $this->em->getRepository(ComplianceDocument::class)->findBy(['company' => $company->getId()]));

        // Defense in depth: even a rogue entityManager->remove($company) is
        // rejected by the DB (history FKs are ON DELETE RESTRICT).
        $rogueDelete = function () use ($archived): void {
            $this->em->remove($archived);
            $this->em->flush();
        };
        $this->expectException(\Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException::class);
        $rogueDelete();
    }

    public function testUserDeactivationPreservesActivitiesAndAuditLogs(): void
    {
        $user = new User();
        $user->setEmail('deactivate-user@example.com');
        $user->setPassword('$2y$13$9UmWR.BDzgbAJEzpkjq9suqeNiIIA6dGpmOe0Em/ClzFAZEIWMOCq');
        $user->setRoles(['ROLE_USER']);
        $user->setFirstName('Cas');
        $user->setLastName('Cade');
        $this->em->persist($user);

        $company = $this->company('Deactivate Co');
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

        // The supported lifecycle: deactivate (optionally pseudonymize),
        // never delete — historical attribution must survive.
        $user->deactivate($user);
        $user->pseudonymize();
        $this->em->flush();
        $this->em->clear();

        $preservedUser = $this->em->getRepository(User::class)->find($user->getId());
        $this->assertNotNull($preservedUser, 'The user row must be preserved for historical attribution');
        $this->assertFalse($preservedUser->isActive());
        $this->assertNotNull($preservedUser->getDeactivatedAt());
        $this->assertSame('Former', $preservedUser->getFirstName());
        $this->assertStringContainsString('@invalid.local', (string) $preservedUser->getEmail());

        $preservedActivity = $this->em->getRepository(Activity::class)->find($activity->getId());
        $this->assertNotNull($preservedActivity, 'Activities must never be deleted with the user');
        $this->assertSame($preservedUser->getId(), $preservedActivity->getUser()?->getId(), 'Activity attribution is preserved exactly');

        // Audit logs keep pointing at the (preserved) user.
        foreach ($this->em->getRepository(AuditLog::class)->findAll() as $log) {
            $this->assertTrue($log->getUser() === null || $log->getUser()->getId() === $preservedUser->getId());
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
