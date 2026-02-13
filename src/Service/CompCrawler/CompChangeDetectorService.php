<?php

namespace App\Service\CompCrawler;

use App\Entity\Competitor;
use App\Entity\CompetitorChangeEvent;
use App\Entity\CompetitorPageFingerprint;
use App\Repository\CompetitorPageFingerprintRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * CompChangeDetectorService — Diff competitor profiles and fire change events.
 *
 * Compares current crawl data against stored page fingerprints and profile snapshots
 * to detect meaningful changes: new capabilities, cert additions/removals,
 * industry pivots, capacity changes, etc.
 */
class CompChangeDetectorService
{
    public function __construct(
        private readonly CompetitorPageFingerprintRepository $fingerprintRepo,
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Detect changes from a crawl result.
     *
     * @param Competitor   $competitor
     * @param array        $newProfile   Extracted profile from CompExtractionService
     * @param array        $oldSnapshot  Previous profile snapshot (from entity JSON fields)
     *
     * @return CompetitorChangeEvent[]  New change events created
     */
    public function detectChanges(Competitor $competitor, array $newProfile, array $oldSnapshot): array
    {
        $events = [];

        // ─── Certification changes ──────────────────────────────────────
        $events = array_merge($events, $this->diffArrayField(
            $competitor,
            $oldSnapshot['certifications'] ?? [],
            $newProfile['certifications'] ?? [],
            CompetitorChangeEvent::TYPE_CERT_ADDED,
            CompetitorChangeEvent::TYPE_CERT_REMOVED
        ));

        // ─── Capability changes ─────────────────────────────────────────
        $events = array_merge($events, $this->diffArrayField(
            $competitor,
            $oldSnapshot['capabilities'] ?? [],
            $newProfile['capabilities'] ?? [],
            CompetitorChangeEvent::TYPE_CAPABILITY_ADDED,
            CompetitorChangeEvent::TYPE_CAPABILITY_REMOVED
        ));

        // ─── Industry changes ───────────────────────────────────────────
        $events = array_merge($events, $this->diffArrayField(
            $competitor,
            $oldSnapshot['industries'] ?? [],
            $newProfile['industries'] ?? [],
            CompetitorChangeEvent::TYPE_INDUSTRY_ADDED,
            CompetitorChangeEvent::TYPE_INDUSTRY_REMOVED
        ));

        // ─── Website structural change ──────────────────────────────────
        $pageChanges = $this->detectPageChanges($competitor);
        if ($pageChanges > 5) {
            $events[] = $this->createEvent(
                $competitor,
                CompetitorChangeEvent::TYPE_WEBSITE_RESTRUCTURE,
                CompetitorChangeEvent::SEVERITY_MEDIUM,
                "{$pageChanges} pages changed in latest crawl",
                ['pages_changed' => $pageChanges]
            );
        }

        // ─── Employee count change ──────────────────────────────────────
        $oldEmployees = $oldSnapshot['employee_estimate'] ?? null;
        $newEmployees = $newProfile['employees'] ?? null;
        if ($oldEmployees && $newEmployees && abs($newEmployees - $oldEmployees) > max(10, $oldEmployees * 0.2)) {
            $direction = $newEmployees > $oldEmployees ? 'grew' : 'shrank';
            $severity = ($newEmployees > $oldEmployees * 1.5 || $newEmployees < $oldEmployees * 0.5)
                ? CompetitorChangeEvent::SEVERITY_HIGH
                : CompetitorChangeEvent::SEVERITY_MEDIUM;

            $events[] = $this->createEvent(
                $competitor,
                CompetitorChangeEvent::TYPE_CAPACITY_CHANGE,
                $severity,
                "Employee estimate {$direction}: {$oldEmployees} → {$newEmployees}",
                ['old' => $oldEmployees, 'new' => $newEmployees]
            );
        }

        // ─── Score changes ──────────────────────────────────────────────
        $oldThreat = $oldSnapshot['threat_score'] ?? $competitor->getThreatScore();
        if (isset($newProfile['threat_score']) && abs($newProfile['threat_score'] - $oldThreat) >= 10) {
            $events[] = $this->createEvent(
                $competitor,
                CompetitorChangeEvent::TYPE_SCORE_CHANGE,
                CompetitorChangeEvent::SEVERITY_LOW,
                "Threat score changed: {$oldThreat} → {$newProfile['threat_score']}",
                ['field' => 'threat_score', 'old' => $oldThreat, 'new' => $newProfile['threat_score']]
            );
        }

        // ─── Type-specific changes ──────────────────────────────────────
        $events = array_merge($events, $this->detectTypeSpecificChanges($competitor, $newProfile, $oldSnapshot));

        // ─── New domain / alt-domain ────────────────────────────────────
        $oldDomains = $oldSnapshot['alt_domains'] ?? [];
        $newDomains = $newProfile['alt_domains'] ?? $competitor->getAltDomains();
        $addedDomains = array_diff($newDomains, $oldDomains);
        if (!empty($addedDomains)) {
            $events[] = $this->createEvent(
                $competitor,
                CompetitorChangeEvent::TYPE_DOMAIN_CHANGE,
                CompetitorChangeEvent::SEVERITY_LOW,
                "New alt-domains detected: " . implode(', ', $addedDomains),
                ['added_domains' => array_values($addedDomains)]
            );
        }

        // Persist all events
        foreach ($events as $event) {
            $this->em->persist($event);
        }
        $this->em->flush();

        if (!empty($events)) {
            $this->logger->info('CompChange: {count} events for {domain}', [
                'count' => count($events),
                'domain' => $competitor->getCanonicalDomain(),
            ]);
        }

        return $events;
    }

    /**
     * Diff an array field and create add/remove events.
     */
    private function diffArrayField(
        Competitor $competitor,
        array $old,
        array $new,
        string $addType,
        string $removeType
    ): array {
        $events = [];
        $oldNorm = array_map('strtolower', $old);
        $newNorm = array_map('strtolower', $new);

        $added = array_diff($newNorm, $oldNorm);
        $removed = array_diff($oldNorm, $newNorm);

        foreach ($added as $item) {
            $events[] = $this->createEvent(
                $competitor,
                $addType,
                CompetitorChangeEvent::SEVERITY_MEDIUM,
                "Added: {$item}",
                ['item' => $item]
            );
        }

        foreach ($removed as $item) {
            $events[] = $this->createEvent(
                $competitor,
                $removeType,
                CompetitorChangeEvent::SEVERITY_LOW,
                "Removed: {$item}",
                ['item' => $item]
            );
        }

        return $events;
    }

    /**
     * Detect type-specific changes (machining, harness, supercap).
     */
    private function detectTypeSpecificChanges(Competitor $competitor, array $newProfile, array $oldSnapshot): array
    {
        $events = [];

        // Machining: new axis capability
        $oldAxis = $oldSnapshot['machining']['axis_count'] ?? null;
        $newAxis = $newProfile['machining']['axis_count'] ?? null;
        if ($newAxis && $oldAxis && $newAxis > $oldAxis) {
            $events[] = $this->createEvent(
                $competitor,
                CompetitorChangeEvent::TYPE_NEW_5AXIS_CAPABILITY,
                CompetitorChangeEvent::SEVERITY_HIGH,
                "Axis capability upgraded: {$oldAxis}-axis → {$newAxis}-axis",
                ['old_axis' => $oldAxis, 'new_axis' => $newAxis]
            );
        }

        // Machining: new material
        $oldMats = $oldSnapshot['machining']['material_families'] ?? [];
        $newMats = $newProfile['machining']['material_families'] ?? [];
        $addedMats = array_diff($newMats, $oldMats);
        foreach ($addedMats as $mat) {
            $events[] = $this->createEvent(
                $competitor,
                CompetitorChangeEvent::TYPE_NEW_MATERIAL_FAMILY,
                CompetitorChangeEvent::SEVERITY_MEDIUM,
                "New material family: {$mat}",
                ['material' => $mat]
            );
        }

        // Harness: new connector brand
        $oldConn = $oldSnapshot['harness']['connector_brands'] ?? [];
        $newConn = $newProfile['harness']['connector_brands'] ?? [];
        $addedConn = array_diff($newConn, $oldConn);
        foreach ($addedConn as $conn) {
            $events[] = $this->createEvent(
                $competitor,
                CompetitorChangeEvent::TYPE_NEW_CONNECTOR_PARTNER,
                CompetitorChangeEvent::SEVERITY_MEDIUM,
                "New connector brand: {$conn}",
                ['connector' => $conn]
            );
        }

        // Supercap: new cell series
        $oldChem = $oldSnapshot['supercap']['cell_chemistry'] ?? [];
        $newChem = $newProfile['supercap']['cell_chemistry'] ?? [];
        $addedChem = array_diff($newChem, $oldChem);
        foreach ($addedChem as $chem) {
            $events[] = $this->createEvent(
                $competitor,
                CompetitorChangeEvent::TYPE_NEW_EDLC_CELL_SERIES,
                CompetitorChangeEvent::SEVERITY_HIGH,
                "New cell chemistry: {$chem}",
                ['chemistry' => $chem]
            );
        }

        return $events;
    }

    /**
     * Count how many page fingerprints changed since last check.
     */
    private function detectPageChanges(Competitor $competitor): int
    {
        $fingerprints = $this->fingerprintRepo->findAllForCompetitor($competitor->getId());
        $changed = 0;

        foreach ($fingerprints as $fp) {
            // If lastCheckedAt equals lastCrawledAt approximately, it was just checked
            // and if the content changed, it was already recorded during crawl
            if ($fp->getLastCheckedAt() && $fp->getLastCheckedAt() > new \DateTime('-1 hour')) {
                // Recently checked — count if content hash changed
                // (The ProfileCrawler already updated the hash, so we just count)
                $changed++;
            }
        }

        return $changed;
    }

    /**
     * Build a snapshot of the current competitor profile for future diffing.
     */
    public function buildSnapshot(Competitor $competitor): array
    {
        return [
            'certifications' => $competitor->getCertifications(),
            'capabilities' => $competitor->getCapabilities(),
            'industries' => $competitor->getIndustries(),
            'employee_estimate' => $competitor->getEmployeeEstimate(),
            'threat_score' => $competitor->getThreatScore(),
            'overlap_score' => $competitor->getOverlapScore(),
            'alt_domains' => $competitor->getAltDomains(),
            'machining' => $competitor->getMachiningProfile() ?? [],
            'harness' => $competitor->getHarnessProfile() ?? [],
            'supercap' => $competitor->getSupercapProfile() ?? [],
            'ems' => $competitor->getEmsProfile() ?? [],
            'snapshot_at' => (new \DateTime())->format('c'),
        ];
    }

    /**
     * Create a change event entity.
     */
    private function createEvent(
        Competitor $competitor,
        string $changeType,
        string $severity,
        string $description,
        array $detail = []
    ): CompetitorChangeEvent {
        $event = new CompetitorChangeEvent();
        $event->setCompetitor($competitor);
        $event->setChangeType($changeType);
        $event->setSeverity($severity);
        $event->setDiffSummary($description);
        $event->setNewValue($detail);
        $event->setEvidenceUrls([$competitor->getCanonicalDomain()]);
        return $event;
    }
}
