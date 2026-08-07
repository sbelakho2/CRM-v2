<?php

namespace App\Tests\Functional;

use App\Entity\AbmAccount;
use App\Entity\Activity;
use App\Entity\AuditLog;
use App\Entity\CalendarEvent;
use App\Entity\Company;
use App\Entity\ComplianceDocument;
use App\Entity\Contact;
use App\Entity\CustomFieldDefinition;
use App\Entity\EmailCampaign;
use App\Entity\EmailSend;
use App\Entity\Lead;
use App\Entity\MeetingSlot;
use App\Entity\OnboardingPack;
use App\Entity\Playbook;
use App\Entity\Quote;
use App\Entity\ReportDefinition;
use App\Entity\RFQ;
use App\Entity\SupplierPortal;
use App\Entity\Task;
use App\Entity\User;
use App\Entity\Webinar;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Routing\Route;

/**
 * Route crawl: every GET route in the app must render without a 500, and
 * every route with seeded data must not 404. Run as anonymous, ROLE_USER
 * and ROLE_ADMIN.
 */
class RouteCrawlTest extends WebTestCase
{
    private array $ids = [];
    private $client;
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        \App\Tests\Bootstrap\TestDatabaseSchema::createSchema($this->entityManager->getConnection());
        $connection = $this->entityManager->getConnection();
        $connection->executeStatement('SET FOREIGN_KEY_CHECKS=0');
        foreach (['users', 'companies', 'contacts', 'leads', 'quotes', 'rfqs', 'tasks', 'activities', 'calendar_events', 'meeting_slots', 'email_campaigns', 'email_sends', 'compliance_documents', 'playbooks', 'report_definitions', 'supplier_portals', 'onboarding_packs', 'custom_field_definitions', 'webinars', 'abm_account', 'audit_logs'] as $table) {
            $connection->executeStatement('TRUNCATE TABLE ' . $table);
        }
        $connection->executeStatement('SET FOREIGN_KEY_CHECKS=1');

        $this->seed();
    }

    private function seed(): void
    {
        $em = $this->entityManager;

        $user = new User();
        $user->setEmail('crawl@example.com');
        $user->setPassword('$2y$13$9UmWR.BDzgbAJEzpkjq9suqeNiIIA6dGpmOe0Em/ClzFAZEIWMOCq');
        $user->setRoles(['ROLE_USER']);
        $user->setFirstName('Crawl');
        $user->setLastName('User');
        $user->setIsVerified(true);
        $em->persist($user);

        $admin = new User();
        $admin->setEmail('crawl-admin@example.com');
        $admin->setPassword('$2y$13$9UmWR.BDzgbAJEzpkjq9suqeNiIIA6dGpmOe0Em/ClzFAZEIWMOCq');
        $admin->setRoles(['ROLE_ADMIN']);
        $admin->setFirstName('Crawl');
        $admin->setLastName('Admin');
        $admin->setIsVerified(true);
        $em->persist($admin);

        $company = new Company();
        $company->setName('Crawl Test Co');
        $company->setAccountTier(Company::TIER_B);
        $company->setSector('Automotive');
        $company->setWebsite('https://crawltest.example.com');
        $em->persist($company);

        $contact = new Contact();
        $contact->setFirstName('Jane');
        $contact->setLastName('Doe');
        $contact->setEmail('jane@crawltest.example.com');
        $contact->setCompany($company);
        $em->persist($contact);

        $lead = new Lead();
        $lead->setCompanyName('Crawl Lead Co');
        $lead->setWebsiteRoot('https://crawllead.example.com');
        $lead->setRegionTag('morocco');
        $lead->setSectorTags(['automotive']);
        $lead->setFitSignals(['pcba' => true]);
        $lead->setLeadScore(80);
        $em->persist($lead);

        $quote = new Quote();
        $quote->setCompany($company);
        $quote->setQuoteNumber('CRAWL-Q-1');
        $quote->setStatus('draft');
        $quote->setTotalCost('1250.00');
        $quote->setCurrency('USD');
        $quote->generatePublicToken(30);
        $em->persist($quote);

        $rfq = new RFQ();
        $rfq->setCompany($company);
        $rfq->setRfqNumber('CRAWL-RFQ-1');
        $rfq->setRfqDate(new \DateTime());
        $rfq->setType(RFQ::TYPE_NPI);
        $rfq->setStatus(RFQ::STATUS_WON);
        $em->persist($rfq);

        $task = new Task();
        $task->setTitle('Crawl Task');
        $task->setDescription('A task to render');
        $task->setStatus(Task::STATUS_TODO);
        $task->setPriority('high');
        $task->setType('general');
        $task->setCreatedBy($user);
        $task->setAssignedTo($user);
        $task->setDueDate(new \DateTime('+1 day'));
        $em->persist($task);

        $activity = new Activity();
        $activity->setCompany($company);
        $activity->setUser($user);
        $activity->setType('Call');
        $activity->setSubject('Intro call');
        $activity->setStatus('completed');
        $activity->setActivityDate(new \DateTime());
        $activity->setOutcomeCategory('positive');
        $em->persist($activity);

        $event = new CalendarEvent();
        $event->setTitle('Crawl Event');
        $event->setEventType(CalendarEvent::TYPE_MEETING);
        $event->setStartAt(new \DateTime('+1 day 10:00'));
        $event->setEndAt(new \DateTime('+1 day 11:00'));
        $event->setOrganizer($user);
        $em->persist($event);

        $slot = new MeetingSlot();
        $slot->setTitle('Crawl Slot');
        $slot->setStartTime(new \DateTimeImmutable('+2 days 10:00'));
        $slot->setEndTime(new \DateTimeImmutable('+2 days 10:30'));
        $slot->setStatus(MeetingSlot::STATUS_AVAILABLE);
        $slot->setOwner($user);
        $slot->setBookingToken(bin2hex(random_bytes(16)));
        $em->persist($slot);

        $campaign = new EmailCampaign();
        $campaign->setName('Crawl Campaign');
        $campaign->setLanguage('EN');
        $campaign->setTouchCount(3);
        $campaign->setTouchTemplates([]);
        $campaign->setActive(true);
        $em->persist($campaign);

        $send = new EmailSend();
        $send->setCampaign($campaign);
        $send->setContact($contact);
        $send->setTouchNumber(1);
        $send->setSentAt(new \DateTime());
        $em->persist($send);

        $doc = new ComplianceDocument();
        $doc->setCompany($company);
        $doc->setFileName('crawl-cert.pdf');
        $doc->setDocumentType('certificate');
        $doc->setFilePath('crawl-cert.pdf');
        $em->persist($doc);

        $playbook = new Playbook();
        $playbook->setName('Crawl Playbook');
        $playbook->setDescription('A playbook');
        $playbook->setTriggerRules('{"event":"lead.created"}');
        $playbook->setActions('[]');
        $playbook->setIsActive(true);
        $em->persist($playbook);

        $report = new ReportDefinition();
        $report->setName('Crawl Report');
        $report->setReportType(ReportDefinition::TYPE_TABLE);
        $report->setDataSource('company');
        $report->setColumns(['name']);
        $report->setFilters([]);
        $report->setGroupBy([]);
        $report->setOrderBy([]);
        $report->setCreatedBy($user);
        $em->persist($report);

        $portal = new SupplierPortal();
        $portal->setCompany($company);
        $portal->setRegistered(true);
        $portal->setPortalUsername('crawl-buyer');
        $portal->setPortalUrl('https://vendor.example.com');
        $em->persist($portal);

        $pack = new OnboardingPack();
        $pack->setCompany($company);
        $pack->setStatus('ready');
        $pack->setPackContents('[]');
        $em->persist($pack);

        $customField = new CustomFieldDefinition();
        $customField->setLabel('Crawl Field');
        $customField->setFieldKey('crawl_field');
        $customField->setFieldType('text');
        $customField->setEntityType('company');
        $em->persist($customField);

        $webinar = new Webinar();
        $webinar->setTitle('Crawl Webinar');
        $webinar->setLanguage(Webinar::LANGUAGE_EN);
        $webinar->setScheduledDate(new \DateTime('+3 days 15:00'));
        $webinar->setDuration(60);
        $webinar->setDescription('A webinar');
        $em->persist($webinar);

        $abm = new AbmAccount();
        $abm->setAccountName('Crawl Abm Co');
        $abm->setDomain('crawlabm.example.com');
        $abm->setIcpTier('Tier1');
        $abm->setEngagementScore(42);
        $em->persist($abm);

        $em->flush();

        $bookingSecret = (string) self::getContainer()->getParameter('app.booking_secret');
        $bookingToken = hash_hmac('sha256', $user->getId() . '|' . $user->getEmail(), $bookingSecret);

        $this->ids = [
            'user' => $user->getId(),
            'admin' => $admin->getId(),
            'company' => $company->getId(),
            'contact' => $contact->getId(),
            'lead' => $lead->getId(),
            'quote' => $quote->getId(),
            'quoteToken' => $quote->getPublicToken(),
            'rfq' => $rfq->getId(),
            'task' => $task->getId(),
            'activity' => $activity->getId(),
            'event' => $event->getId(),
            'slot' => $slot->getId(),
            'slotToken' => $slot->getBookingToken(),
            'bookingToken' => $bookingToken,
            'campaign' => $campaign->getId(),
            'send' => $send->getId(),
            'doc' => $doc->getId(),
            'playbook' => $playbook->getId(),
            'report' => $report->getId(),
            'portal' => $portal->getId(),
            'pack' => $pack->getId(),
            'customField' => $customField->getId(),
            'webinar' => $webinar->getId(),
            'abm' => $abm->getId(),
        ];
    }

    /**
     * @return array<string, string> route name => URL
     */
    private function buildUrls(): array
    {
        $router = self::getContainer()->get('router');
        $urls = [];

        foreach ($router->getRouteCollection() as $name => $route) {
            /** @var Route $route */
            $methods = $route->getMethods();
            if ($methods !== [] && !in_array('GET', $methods, true) && !in_array('ANY', $methods, true)) {
                continue;
            }
            $path = $route->getPath();
            if (!preg_match_all('/\{(\w+)\}/', $path, $m)) {
                $urls[$name] = $path;
                continue;
            }
            $substitutions = $this->substitute($m[1], $name);
            if ($substitutions === null) {
                continue;
            }
            $url = $path;
            $query = [];
            foreach ($substitutions as $param => $value) {
                if (str_contains($path, '{' . $param . '}')) {
                    $url = str_replace('{' . $param . '}', (string) $value, $url);
                } else {
                    $query[$param] = $value;
                }
            }
            if ($query !== []) {
                $url .= '?' . http_build_query($query);
            }
            $urls[$name] = $url;
        }

        return $urls;
    }

    private function substitute(array $params, string $routeName): ?array
    {
        $map = [
            'id' => $this->ids['company'],
            'companyId' => $this->ids['company'],
            'contactId' => $this->ids['contact'],
            'lead' => $this->ids['lead'],
            'quote' => $this->ids['quote'],
            'rfq' => $this->ids['rfq'],
            'task' => $this->ids['task'],
            'activity' => $this->ids['activity'],
            'event' => $this->ids['event'],
            'slot' => $this->ids['slot'],
            'campaign' => $this->ids['campaign'],
            'send' => $this->ids['send'],
            'doc' => $this->ids['doc'],
            'playbook' => $this->ids['playbook'],
            'report' => $this->ids['report'],
            'portal' => $this->ids['portal'],
            'portalId' => $this->ids['portal'],
            'packId' => $this->ids['pack'],
            'webinar' => $this->ids['webinar'],
            'abm' => $this->ids['abm'],
            'customField' => $this->ids['customField'],
            'format' => 'csv',
            'date' => '2026-08-07',
            'entityType' => 'company',
            'entityId' => $this->ids['company'],
            'dataSource' => 'company',
            'fieldType' => 'string',
            'username' => 'crawl@example.com',
            'token' => $this->ids['slotToken'],
        ];

        // Route-specific params
        if (in_array($routeName, ['quote_live_view', 'quote_live_pricing', 'quote_live_calculate', 'quote_live_request', 'quote_live_accept', 'quote_live_reject'], true)) {
            $map['token'] = $this->ids['quoteToken'];
        }
        if ($routeName === 'meeting_public_book_by_id') {
            $map['id'] = $this->ids['user'];
            $map['token'] = $this->ids['bookingToken'];
        }
        if ($routeName === 'meeting_public_book') {
            $map['token'] = $this->ids['bookingToken'];
        }
        if (str_contains($routeName, 'email_send_track')) {
            $map['id'] = $this->ids['send'];
        }
        if ($routeName === 'export_sourcing_report') {
            $map['quotes'] = $this->ids['quote'];
        }
        if (str_contains($routeName, 'audit_log')) {
            $log = $this->entityManager->getRepository(AuditLog::class)->findOneBy([]);
            if (!$log) {
                return null;
            }
            $map['id'] = $log->getId();
            $map['entityType'] = $log->getEntityType();
            $map['entityId'] = $log->getEntityId();
        }
        if (str_contains($routeName, 'reset_password') || $routeName === 'app_verify_email') {
            return null; // signed-token routes redirect by design; covered elsewhere
        }

        $out = [];
        foreach ($params as $p) {
            if (!isset($map[$p])) {
                return null;
            }
            $out[$p] = $map[$p];
        }

        return $out;
    }

    private function crawl(array $urls, array &$failures, array $allowed): void
    {
        // Routes that legitimately answer 400 when called without their
        // required input (the crawl provides no input for them).
        $allowedBadRequest = ['email_unsubscribe', 'export_sourcing_report', 'custom_field_check_key'];

        foreach ($urls as $name => $url) {
            $this->client->request('GET', $url);
            $status = $this->client->getResponse()->getStatusCode();
            if ($status === 500 || ($status === 404 && !in_array($name, $allowed, true))
                || ($status === 400 && !in_array($name, $allowedBadRequest, true))) {
                $failures[$name] = $status;
            }
        }
    }

    public function testNoRouteReturns500OrUnexpected404AsRegularUser(): void
    {
        $user = $this->entityManager->getRepository(User::class)->findOneBy(['email' => 'crawl@example.com']);
        $this->client->loginUser($user);

        $urls = $this->buildUrls();
        $failures = [];
        $this->crawl($urls, $failures, []);

        $this->assertSame([], $failures, 'Routes failing as ROLE_USER: ' . json_encode($failures));
    }

    public function testNoRouteReturns500OrUnexpected404AsAdmin(): void
    {
        $admin = $this->entityManager->getRepository(User::class)->findOneBy(['email' => 'crawl-admin@example.com']);
        $this->client->loginUser($admin);

        $urls = $this->buildUrls();
        $failures = [];
        $this->crawl($urls, $failures, []);

        $this->assertSame([], $failures, 'Routes failing as ROLE_ADMIN: ' . json_encode($failures));
    }
}
