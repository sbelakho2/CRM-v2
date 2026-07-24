<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Company;
use App\Entity\Contact;
use App\Entity\Lead;
use App\Entity\OutboundMessage;
use App\Entity\RFQ;
use App\Repository\RFQRepository;
use App\Repository\OutboundMessageRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Sales Pipeline Orchestrator
 *
 * Central coordination service that connects all sales modules:
 *   Lead → Company → RFQ → Quote → Award
 *
 * Responsibilities:
 * 1. Auto-creates draft RFQ when a Lead is converted to a Company
 * 2. Advances Company.pipelineStage based on RFQ status transitions
 * 3. Creates opportunity (RFQ) when outbound email engagement is positive
 * 4. Persists Lead.nurturingStage whenever stage is computed
 * 5. Feeds RFQ win/loss outcomes back into lead scoring data
 *
 * All state changes go through the EntityManager — nothing is left in-memory only.
 * All pipeline stage transitions are wrapped in DB transactions for atomicity.
 */
class SalesPipelineOrchestratorService
{
    // Map RFQ status → minimum Company pipelineStage
    private const RFQ_STATUS_TO_STAGE = [
        'Pending'   => Company::STAGE_MQL,
        'In Review' => Company::STAGE_SQL,
        'Submitted' => Company::STAGE_PROPOSAL,
        'Won'       => Company::STAGE_AWARD,
        // 'Lost' does NOT regress — we only advance or keep.
    ];

    public function __construct(
        private EntityManagerInterface  $entityManager,
        private RFQRepository           $rfqRepository,
        private OutboundMessageRepository $outboundRepository,
        private LoggerInterface         $logger,
    ) {}

    // ================================================================
    // 1. LEAD → COMPANY CONVERSION  (auto-create draft RFQ)
    // ================================================================

    /**
     * Called immediately after LeadController::convert() creates a Company.
     * Creates a "Pending" draft RFQ attached to the new company and the
     * originating lead so the relationship is fully traceable.
     *
     * Entire operation is wrapped in a DB transaction for atomicity.
     */
    public function afterLeadConverted(Company $company, Lead $lead): ?RFQ
    {
        $this->entityManager->beginTransaction();
        try {
            // Persist nurturing stage as "converted"
            $lead->setNurturingStage('converted');

            // Create draft RFQ
            $rfq = new RFQ();
            $rfq->setCompany($company);
            $rfq->setLead($lead);
            $rfq->setType('Standard RFQ');
            $rfq->setStatus('Pending');
            $rfq->setRfqDate(new \DateTime());
            $rfq->setCurrency('EUR');

            // Auto-generate RFQ number: RFQ-{companyId}-{timestamp}
            $rfq->setRfqNumber('RFQ-' . $company->getId() . '-' . date('Ymd'));

            // Seed technical scope from lead data
            $scope = [];
            if ($lead->getSectorTags()) {
                $scope[] = 'Sectors: ' . implode(', ', $lead->getSectorTags());
            }
            if ($lead->getQualityStack()) {
                $scope[] = 'Quality: ' . implode(', ', $lead->getQualityStack());
            }
            if ($lead->getFitSignals()) {
                $signals = array_keys(array_filter($lead->getFitSignals()));
                if ($signals) {
                    $scope[] = 'Capabilities: ' . implode(', ', $signals);
                }
            }
            if ($scope) {
                $rfq->setTechnicalScope(implode("\n", $scope));
            }

            $rfq->setNotes('Auto-created from Lead #' . $lead->getId() . ' conversion on ' . date('Y-m-d H:i'));

            // Link primary contact if one exists
            $primaryContact = $this->findPrimaryContact($company);
            if ($primaryContact) {
                $rfq->setContact($primaryContact);
            }

            $this->entityManager->persist($rfq);

            // Advance company to MQL (has an RFQ now)
            $this->advanceCompanyStage($company, Company::STAGE_MQL);

            $this->entityManager->flush();
            $this->entityManager->commit();

            $this->logger->info('Pipeline: Auto-created draft RFQ from lead conversion', [
                'rfq_id'     => $rfq->getId(),
                'company_id' => $company->getId(),
                'lead_id'    => $lead->getId(),
            ]);

            return $rfq;
        } catch (\Exception $e) {
            $this->entityManager->rollback();
            $this->logger->error('Pipeline: Failed to create RFQ from lead conversion', [
                'company_id' => $company->getId(),
                'lead_id'    => $lead->getId(),
                'error'      => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    // ================================================================
    // 2. RFQ STATUS CHANGE → COMPANY STAGE ADVANCEMENT
    // ================================================================

    /**
     * Called when RFQController::updateStatus() changes the status.
     * Advances the parent Company's pipelineStage to match the highest
     * RFQ status — stage never goes backward.
     *
     * Entire operation is wrapped in a DB transaction for atomicity.
     */
    public function afterRfqStatusChanged(RFQ $rfq, string $oldStatus, string $newStatus): void
    {
        $this->entityManager->beginTransaction();
        try {
            $company = $rfq->getCompany();
            if (!$company) {
                $this->entityManager->rollback();
                return;
            }

            $targetStage = self::RFQ_STATUS_TO_STAGE[$newStatus] ?? null;

            if ($targetStage) {
                $this->advanceCompanyStage($company, $targetStage);
            }

            // If Won → set company status to 'active' (eligible for compliance pipeline)
            if ($newStatus === 'Won' && $company->getCompanyStatus() !== Company::STATUS_ACTIVE) {
                $company->setCompanyStatus(Company::STATUS_ACTIVE);
                $this->logger->info('Pipeline: Company activated after RFQ win', [
                    'company_id' => $company->getId(),
                ]);
            }

            // Feed outcome into lead record for scoring feedback
            $lead = $rfq->getLead();
            if ($lead && in_array($newStatus, ['Won', 'Lost'], true)) {
                $this->feedOutcomeToLead($lead, $newStatus, $rfq);
            }

            $this->entityManager->flush();
            $this->entityManager->commit();

            $this->logger->info('Pipeline: RFQ status change processed', [
                'rfq_id'       => $rfq->getId(),
                'old_status'   => $oldStatus,
                'new_status'   => $newStatus,
                'company_id'   => $company->getId(),
                'company_stage' => $company->getPipelineStage(),
            ]);
        } catch (\Exception $e) {
            $this->entityManager->rollback();
            $this->logger->error('Pipeline: Failed to process RFQ status change', [
                'rfq_id'     => $rfq->getId(),
                'old_status' => $oldStatus,
                'new_status' => $newStatus,
                'error'      => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    // ================================================================
    // 3. EMAIL ENGAGEMENT → OPPORTUNITY CREATION
    // ================================================================

    /**
     * Called by AutonomousSalesOrchestratorService::recordEmailEvent()
     * when a positive reply is classified (interested, meeting_request,
     * information_request).
     *
     * Creates a new RFQ (opportunity) linked to the contact's company,
     * and links the outbound message to the new RFQ for attribution.
     *
     * Entire operation is wrapped in a DB transaction for atomicity.
     */
    public function afterPositiveEmailEngagement(OutboundMessage $message, string $classification): ?RFQ
    {
        $this->entityManager->beginTransaction();
        try {
            $contact = $message->getContact();
            if (!$contact) {
                $this->entityManager->rollback();
                return null;
            }

            $company = $contact->getCompany();
            if (!$company) {
                $this->entityManager->rollback();
                return null;
            }

            // Check if company already has an open RFQ — don't create duplicates
            $openRfqs = $this->rfqRepository->findBy([
                'company' => $company,
                'status'  => ['Pending', 'In Review', 'Submitted'],
            ]);

            if (count($openRfqs) > 0) {
                // Link message to existing open RFQ instead
                $existingRfq = $openRfqs[0];
                $message->setRfq($existingRfq);

                $this->logger->info('Pipeline: Linked positive reply to existing RFQ', [
                    'message_id' => $message->getId(),
                    'rfq_id'     => $existingRfq->getId(),
                ]);

                // Still advance company stage
                $this->advanceCompanyStage($company, Company::STAGE_SQL);

                $this->entityManager->flush();
                $this->entityManager->commit();

                return $existingRfq;
            }

            // Create new opportunity RFQ
            $rfq = new RFQ();
            $rfq->setCompany($company);
            $rfq->setContact($contact);
            $rfq->setType('Standard RFQ');
            $rfq->setStatus('Pending');
            $rfq->setRfqDate(new \DateTime());
            $rfq->setCurrency('EUR');
            $rfq->setRfqNumber('RFQ-OB-' . $company->getId() . '-' . date('Ymd'));
            $rfq->setNotes(sprintf(
                "Auto-created from positive email engagement (%s)\nContact: %s %s\nReply classification: %s\nMessage ID: #%d",
                date('Y-m-d H:i'),
                $contact->getFirstName(),
                $contact->getLastName(),
                $classification,
                $message->getId()
            ));

            $this->entityManager->persist($rfq);

            // Link the message to the new RFQ
            $message->setRfq($rfq);

            // Advance company stage to SQL (sales-qualified due to positive response)
            $this->advanceCompanyStage($company, Company::STAGE_SQL);

            $this->entityManager->flush();
            $this->entityManager->commit();

            $this->logger->info('Pipeline: Created opportunity RFQ from positive email engagement', [
                'rfq_id'         => $rfq->getId(),
                'company_id'     => $company->getId(),
                'contact_id'     => $contact->getId(),
                'classification' => $classification,
                'message_id'     => $message->getId(),
            ]);

            return $rfq;
        } catch (\Exception $e) {
            $this->entityManager->rollback();
            $this->logger->error('Pipeline: Failed to create RFQ from email engagement', [
                'message_id' => $message->getId(),
                'error'      => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    // ================================================================
    // 4. NURTURING STAGE PERSISTENCE
    // ================================================================

    /**
     * Persist the computed nurturing stage onto the Lead entity.
     * Called by LeadNurturingService after computing the stage.
     */
    public function persistNurturingStage(Lead $lead, string $stage): void
    {
        if ($lead->getNurturingStage() !== $stage) {
            $lead->setNurturingStage($stage);

            $this->logger->debug('Pipeline: Lead nurturing stage persisted', [
                'lead_id' => $lead->getId(),
                'stage'   => $stage,
            ]);
        }
    }

    // ================================================================
    // 5. WIN/LOSS FEEDBACK TO LEAD SCORING
    // ================================================================

    /**
     * When an RFQ is Won or Lost, feed the outcome back into the
     * originating Lead's notesAuto for scoring model learning.
     */
    private function feedOutcomeToLead(Lead $lead, string $outcome, RFQ $rfq): void
    {
        $feedback = sprintf(
            "\n[Pipeline Feedback %s] RFQ #%s → %s",
            date('Y-m-d'),
            $rfq->getRfqNumber() ?? $rfq->getId(),
            $outcome
        );

        if ($outcome === 'Won') {
            $feedback .= $rfq->getWinFactors() ? ' | Win factors: ' . $rfq->getWinFactors() : '';
            // Boost lead score for won deals
            $currentScore = $lead->getLeadScore() ?? 0;
            $lead->setLeadScore(min(100, $currentScore + 10));
        } elseif ($outcome === 'Lost') {
            $feedback .= $rfq->getLossReason() ? ' | Loss reason: ' . $rfq->getLossReason() : '';
        }

        $existingNotes = $lead->getNotesAuto() ?? '';
        $lead->setNotesAuto($existingNotes . $feedback);
    }

    // ================================================================
    // HELPERS
    // ================================================================

    /**
     * Advance company's pipelineStage to at least $targetStage.
     * Stage only moves forward, never backward.
     */
    private function advanceCompanyStage(Company $company, string $targetStage): void
    {
        $stageOrder = array_flip(Company::VALID_STAGES);
        $currentIndex = $stageOrder[$company->getPipelineStage()] ?? 0;
        $targetIndex  = $stageOrder[$targetStage] ?? 0;

        if ($targetIndex > $currentIndex) {
            $company->setPipelineStage($targetStage);

            $this->logger->info('Pipeline: Company stage advanced', [
                'company_id' => $company->getId(),
                'from_stage'  => Company::VALID_STAGES[$currentIndex],
                'to_stage'    => $targetStage,
            ]);
        }
    }

    /**
     * Find primary contact for a company, or first contact if no primary set.
     */
    private function findPrimaryContact(Company $company): ?Contact
    {
        foreach ($company->getContacts() as $contact) {
            if ($contact->isPrimaryContact()) {
                return $contact;
            }
        }

        // Fall back to first contact
        return $company->getContacts()->first() ?: null;
    }
}
