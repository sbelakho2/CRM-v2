<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\RFQ;
use App\Entity\RfqLineItem;
use App\Entity\RfqVersion;
use App\Repository\RfqLineItemRepository;
use App\Repository\RfqVersionRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * RFQ Versioning Service
 * 
 * Manages RFQ versioning and line items:
 * - Creates new versions when quotes are revised
 * - Tracks line item changes
 * - Maintains audit trail
 */
class RfqVersioningService
{
    private EntityManagerInterface $entityManager;
    private RfqVersionRepository $versionRepository;
    private RfqLineItemRepository $lineItemRepository;
    private LoggerInterface $logger;
    
    public function __construct(
        EntityManagerInterface $entityManager,
        RfqVersionRepository $versionRepository,
        RfqLineItemRepository $lineItemRepository,
        LoggerInterface $logger
    ) {
        $this->entityManager = $entityManager;
        $this->versionRepository = $versionRepository;
        $this->lineItemRepository = $lineItemRepository;
        $this->logger = $logger;
    }
    
    /**
     * Create initial version for a new RFQ
     */
    public function createInitialVersion(RFQ $rfq, ?string $createdBy = null): RfqVersion
    {
        $version = null;
        
        $this->entityManager->wrapInTransaction(function () use ($rfq, $createdBy, &$version) {
            $newVersion = new RfqVersion();
            $newVersion->setRfq($rfq);
            $newVersion->setVersionNumber(1);
            $newVersion->setRevisionCode('A');
            $newVersion->setStatus('Draft');
            $newVersion->setEstimatedValue($rfq->getEstimatedValue());
            $newVersion->setTechnicalScope($rfq->getTechnicalScope());
            $newVersion->setNotes($rfq->getNotes());
            $newVersion->setCreatedBy($createdBy);
            $newVersion->setLineItemsSnapshot($this->captureLineItems($rfq));
            
            $this->entityManager->persist($newVersion);
            $this->entityManager->flush();
            
            $this->logger->info('Initial RFQ version created', [
                'rfq_id' => $rfq->getId(),
                'rfq_number' => $rfq->getRfqNumber(),
            ]);
            
            $version = $newVersion;
        });
        
        return $version;
    }
    
    /**
     * Create a new revision of an RFQ
     */
    public function createRevision(
        RFQ $rfq,
        string $revisionReason,
        ?string $createdBy = null
    ): RfqVersion {
        $version = null;
        
        $this->entityManager->wrapInTransaction(function () use ($rfq, $revisionReason, $createdBy, &$version) {
            // Mark previous version as superseded
            $latestVersion = $this->versionRepository->findLatestVersion($rfq);
            if ($latestVersion) {
                $latestVersion->supersede();
            }
            
            // Get next version number and revision code
            $nextVersionNumber = $this->versionRepository->getNextVersionNumber($rfq);
            $revisionCode = $this->generateRevisionCode($nextVersionNumber);
            
            // Create new version
            $newVersion = new RfqVersion();
            $newVersion->setRfq($rfq);
            $newVersion->setVersionNumber($nextVersionNumber);
            $newVersion->setRevisionCode($revisionCode);
            $newVersion->setRevisionReason($revisionReason);
            $newVersion->setStatus('Draft');
            $newVersion->setEstimatedValue($rfq->getEstimatedValue());
            $newVersion->setTechnicalScope($rfq->getTechnicalScope());
            $newVersion->setNotes($rfq->getNotes());
            $newVersion->setCreatedBy($createdBy);
            $newVersion->setLineItemsSnapshot($this->captureLineItems($rfq));
            
            // Default validity: 30 days
            $newVersion->setValidUntil((new \DateTime())->modify('+30 days'));
            
            $this->entityManager->persist($newVersion);
            $this->entityManager->flush();
            
            $this->logger->info('New RFQ revision created', [
                'rfq_id' => $rfq->getId(),
                'rfq_number' => $rfq->getRfqNumber(),
                'version' => $nextVersionNumber,
                'revision_code' => $revisionCode,
                'reason' => $revisionReason,
            ]);
            
            $version = $newVersion;
        });
        
        return $version;
    }
    
    /**
     * Submit a version (marks as submitted, records timestamp)
     */
    public function submitVersion(RfqVersion $version): void
    {
        $this->entityManager->wrapInTransaction(function () use ($version) {
            $version->submit();
            $this->entityManager->flush();
            
            $this->logger->info('RFQ version submitted', [
                'rfq_id' => $version->getRfq()?->getId(),
                'version' => $version->getVersionNumber(),
            ]);
        });
    }
    
    /**
     * Get version comparison between two versions
     */
    public function compareVersions(RfqVersion $older, RfqVersion $newer): array
    {
        $changes = [
            'value_change' => null,
            'line_item_changes' => [],
            'scope_changed' => false,
        ];
        
        // Compare estimated values
        $oldValue = (float) ($older->getEstimatedValue() ?? 0);
        $newValue = (float) ($newer->getEstimatedValue() ?? 0);
        
        if ($oldValue !== $newValue) {
            $changes['value_change'] = [
                'from' => $oldValue,
                'to' => $newValue,
                'difference' => $newValue - $oldValue,
                'percentage' => $oldValue > 0 ? (($newValue - $oldValue) / $oldValue) * 100 : null,
            ];
        }
        
        // Compare line items
        $oldItems = $older->getLineItemsSnapshot() ?? [];
        $newItems = $newer->getLineItemsSnapshot() ?? [];
        
        $changes['line_item_changes'] = $this->compareLineItems($oldItems, $newItems);
        
        // Check if technical scope changed
        $changes['scope_changed'] = $older->getTechnicalScope() !== $newer->getTechnicalScope();
        
        return $changes;
    }
    
    /**
     * Add a line item to an RFQ
     */
    public function addLineItem(RFQ $rfq, array $data): RfqLineItem
    {
        $lineItem = null;
        
        $this->entityManager->wrapInTransaction(function () use ($rfq, $data, &$lineItem) {
            $newLineItem = new RfqLineItem();
            $newLineItem->setRfq($rfq);
            $newLineItem->setLineNumber($this->lineItemRepository->getNextLineNumber($rfq));
            
            // Set data
            if (isset($data['partNumber'])) $newLineItem->setPartNumber($data['partNumber']);
            if (isset($data['customerPartNumber'])) $newLineItem->setCustomerPartNumber($data['customerPartNumber']);
            if (isset($data['description'])) $newLineItem->setDescription($data['description']);
            if (isset($data['quantityAnnual'])) $newLineItem->setQuantityAnnual($data['quantityAnnual']);
            if (isset($data['quantityPerBatch'])) $newLineItem->setQuantityPerBatch($data['quantityPerBatch']);
            if (isset($data['unitPrice'])) $newLineItem->setUnitPrice($data['unitPrice']);
            if (isset($data['nrePrice'])) $newLineItem->setNrePrice($data['nrePrice']);
            if (isset($data['currency'])) $newLineItem->setCurrency($data['currency']);
            if (isset($data['leadTimeDays'])) $newLineItem->setLeadTimeDays($data['leadTimeDays']);
            if (isset($data['technology'])) $newLineItem->setTechnology($data['technology']);
            if (isset($data['componentCount'])) $newLineItem->setComponentCount($data['componentCount']);
            if (isset($data['specifications'])) $newLineItem->setSpecifications($data['specifications']);
            if (isset($data['notes'])) $newLineItem->setNotes($data['notes']);
            
            // Boolean flags
            $newLineItem->setRequiresXray($data['requiresXray'] ?? false);
            $newLineItem->setRequiresAoi($data['requiresAoi'] ?? false);
            $newLineItem->setRequiresFunctionalTest($data['requiresFunctionalTest'] ?? false);
            $newLineItem->setRequiresConformalCoating($data['requiresConformalCoating'] ?? false);
            
            $this->entityManager->persist($newLineItem);
            $this->entityManager->flush();
            
            // Update RFQ estimated value
            $this->updateRfqTotalValue($rfq);
            
            $lineItem = $newLineItem;
        });
        
        return $lineItem;
    }
    
    /**
     * Update a line item
     */
    public function updateLineItem(RfqLineItem $lineItem, array $data): void
    {
        $this->entityManager->wrapInTransaction(function () use ($lineItem, $data) {
            if (isset($data['partNumber'])) $lineItem->setPartNumber($data['partNumber']);
            if (isset($data['customerPartNumber'])) $lineItem->setCustomerPartNumber($data['customerPartNumber']);
            if (isset($data['description'])) $lineItem->setDescription($data['description']);
            if (isset($data['quantityAnnual'])) $lineItem->setQuantityAnnual($data['quantityAnnual']);
            if (isset($data['quantityPerBatch'])) $lineItem->setQuantityPerBatch($data['quantityPerBatch']);
            if (isset($data['unitPrice'])) $lineItem->setUnitPrice($data['unitPrice']);
            if (isset($data['nrePrice'])) $lineItem->setNrePrice($data['nrePrice']);
            if (isset($data['currency'])) $lineItem->setCurrency($data['currency']);
            if (isset($data['leadTimeDays'])) $lineItem->setLeadTimeDays($data['leadTimeDays']);
            if (isset($data['technology'])) $lineItem->setTechnology($data['technology']);
            if (isset($data['componentCount'])) $lineItem->setComponentCount($data['componentCount']);
            if (isset($data['specifications'])) $lineItem->setSpecifications($data['specifications']);
            if (isset($data['notes'])) $lineItem->setNotes($data['notes']);
            if (isset($data['status'])) $lineItem->setStatus($data['status']);
            
            if (isset($data['requiresXray'])) $lineItem->setRequiresXray($data['requiresXray']);
            if (isset($data['requiresAoi'])) $lineItem->setRequiresAoi($data['requiresAoi']);
            if (isset($data['requiresFunctionalTest'])) $lineItem->setRequiresFunctionalTest($data['requiresFunctionalTest']);
            if (isset($data['requiresConformalCoating'])) $lineItem->setRequiresConformalCoating($data['requiresConformalCoating']);
            
            $lineItem->setUpdatedAt(new \DateTime());
            
            $this->entityManager->flush();
            
            // Update RFQ estimated value
            $rfq = $lineItem->getRfq();
            if ($rfq) {
                $this->updateRfqTotalValue($rfq);
            }
        });
    }
    
    /**
     * Remove a line item
     */
    public function removeLineItem(RfqLineItem $lineItem): void
    {
        $this->entityManager->wrapInTransaction(function () use ($lineItem) {
            $rfq = $lineItem->getRfq();
            
            $this->entityManager->remove($lineItem);
            $this->entityManager->flush();
            
            if ($rfq) {
                $this->resequenceLineItems($rfq);
                $this->updateRfqTotalValue($rfq);
            }
        });
    }
    
    /**
     * Get line items for an RFQ
     */
    public function getLineItems(RFQ $rfq): array
    {
        return $this->lineItemRepository->findByRfq($rfq);
    }
    
    /**
     * Get version history for an RFQ
     */
    public function getVersionHistory(RFQ $rfq): array
    {
        return $this->versionRepository->getVersionHistory($rfq);
    }
    
    /**
     * Get full versions with snapshots
     */
    public function getVersions(RFQ $rfq): array
    {
        return $this->versionRepository->findByRfq($rfq);
    }
    
    // Private helpers
    
    private function captureLineItems(RFQ $rfq): array
    {
        $lineItems = $this->lineItemRepository->findByRfq($rfq);
        
        return array_map(function (RfqLineItem $item) {
            return [
                'lineNumber' => $item->getLineNumber(),
                'partNumber' => $item->getPartNumber(),
                'customerPartNumber' => $item->getCustomerPartNumber(),
                'description' => $item->getDescription(),
                'quantityAnnual' => $item->getQuantityAnnual(),
                'quantityPerBatch' => $item->getQuantityPerBatch(),
                'unitPrice' => $item->getUnitPrice(),
                'nrePrice' => $item->getNrePrice(),
                'currency' => $item->getCurrency(),
                'leadTimeDays' => $item->getLeadTimeDays(),
                'technology' => $item->getTechnology(),
                'lineTotal' => $item->getLineTotal(),
            ];
        }, $lineItems);
    }
    
    private function generateRevisionCode(int $versionNumber): string
    {
        // Use letters A, B, C, ... for revisions up to 26, then AA, AB, etc.
        if ($versionNumber <= 26) {
            return chr(64 + $versionNumber); // A=1, B=2, etc.
        }
        
        $first = intdiv($versionNumber - 1, 26);
        $second = (($versionNumber - 1) % 26) + 1;
        
        return chr(64 + $first) . chr(64 + $second);
    }
    
    private function compareLineItems(array $oldItems, array $newItems): array
    {
        $changes = [];
        
        // Index by line number
        $oldByLine = [];
        foreach ($oldItems as $item) {
            $oldByLine[$item['lineNumber']] = $item;
        }
        
        $newByLine = [];
        foreach ($newItems as $item) {
            $newByLine[$item['lineNumber']] = $item;
        }
        
        // Find added items
        foreach ($newByLine as $lineNum => $item) {
            if (!isset($oldByLine[$lineNum])) {
                $changes[] = [
                    'type' => 'added',
                    'lineNumber' => $lineNum,
                    'item' => $item,
                ];
            }
        }
        
        // Find removed items
        foreach ($oldByLine as $lineNum => $item) {
            if (!isset($newByLine[$lineNum])) {
                $changes[] = [
                    'type' => 'removed',
                    'lineNumber' => $lineNum,
                    'item' => $item,
                ];
            }
        }
        
        // Find modified items
        foreach ($newByLine as $lineNum => $newItem) {
            if (isset($oldByLine[$lineNum])) {
                $oldItem = $oldByLine[$lineNum];
                $fieldChanges = [];
                
                foreach ($newItem as $field => $value) {
                    if (isset($oldItem[$field]) && $oldItem[$field] !== $value) {
                        $fieldChanges[$field] = [
                            'from' => $oldItem[$field],
                            'to' => $value,
                        ];
                    }
                }
                
                if (!empty($fieldChanges)) {
                    $changes[] = [
                        'type' => 'modified',
                        'lineNumber' => $lineNum,
                        'changes' => $fieldChanges,
                    ];
                }
            }
        }
        
        return $changes;
    }
    
    private function updateRfqTotalValue(RFQ $rfq): void
    {
        // getTotalValue() returns float; setEstimatedValue() expects a string.
        // Round to 2 decimals before casting so float artifacts like
        // "1234.5000000001" never leak into the stored value.
        $total = $this->lineItemRepository->getTotalValue($rfq);
        $rfq->setEstimatedValue((string) round((float) $total, 2));
        $this->entityManager->flush();
    }
    
    private function resequenceLineItems(RFQ $rfq): void
    {
        $lineItems = $this->lineItemRepository->findByRfq($rfq);
        
        $lineNumber = 1;
        foreach ($lineItems as $item) {
            $item->setLineNumber($lineNumber++);
        }
        
        $this->entityManager->flush();
    }
}
