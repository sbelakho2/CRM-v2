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
 *
 * @phpstan-type LineItemSnapshot array{
 *     lineNumber: int|null,
 *     partNumber: string|null,
 *     customerPartNumber: string|null,
 *     description: string|null,
 *     quantityAnnual: int|null,
 *     quantityPerBatch: int|null,
 *     unitPrice: string|null,
 *     nrePrice: string|null,
 *     currency: string|null,
 *     leadTimeDays: int|null,
 *     technology: string|null,
 *     lineTotal: float
 * }
 * @phpstan-type LineItemChange array{
 *     type: 'added'|'removed'|'modified',
 *     lineNumber: int|null,
 *     item?: LineItemSnapshot,
 *     changes?: array<string, array{from: mixed, to: mixed}>
 * }
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

        if (!$version instanceof RfqVersion) {
            // Unreachable while the transaction callback assigns the version;
            // guards against a future callback refactor silently returning null.
            throw new \RuntimeException('Initial RFQ version was not created');
        }

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

        if (!$version instanceof RfqVersion) {
            // Unreachable while the transaction callback assigns the version;
            // guards against a future callback refactor silently returning null.
            throw new \RuntimeException('RFQ revision was not created');
        }

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
     *
     * @return array{value_change: array{from: float, to: float, difference: float, percentage: float|null}|null, line_item_changes: list<LineItemChange>, scope_changed: bool}
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
        /** @var list<LineItemSnapshot> $oldItems */
        $oldItems = $older->getLineItemsSnapshot() ?? [];
        /** @var list<LineItemSnapshot> $newItems */
        $newItems = $newer->getLineItemsSnapshot() ?? [];
        
        $changes['line_item_changes'] = $this->compareLineItems($oldItems, $newItems);
        
        // Check if technical scope changed
        $changes['scope_changed'] = $older->getTechnicalScope() !== $newer->getTechnicalScope();
        
        return $changes;
    }
    
    /**
     * Add a line item to an RFQ
      * @param array<string|int, mixed> $data
     */
    public function addLineItem(RFQ $rfq, array $data): RfqLineItem
    {
        $lineItem = null;

        $this->entityManager->wrapInTransaction(function () use ($rfq, $data, &$lineItem) {
            $newLineItem = new RfqLineItem();
            $newLineItem->setRfq($rfq);
            $newLineItem->setLineNumber($this->lineItemRepository->getNextLineNumber($rfq));

            // Set data
            $this->applyLineItemData($newLineItem, $data);

            $this->entityManager->persist($newLineItem);
            $this->entityManager->flush();

            // Update RFQ estimated value
            $this->updateRfqTotalValue($rfq);

            $lineItem = $newLineItem;
        });

        if (!$lineItem instanceof RfqLineItem) {
            // Unreachable while the transaction callback assigns the item;
            // guards against a future callback refactor silently returning null.
            throw new \RuntimeException('RFQ line item was not created');
        }

        return $lineItem;
    }
    
    /**
     * Update a line item
      * @param array<string|int, mixed> $data
     */
    public function updateLineItem(RfqLineItem $lineItem, array $data): void
    {
        $this->entityManager->wrapInTransaction(function () use ($lineItem, $data) {
            $this->applyLineItemData($lineItem, $data, true);

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
     *
     * @return list<RfqLineItem>
     */
    public function getLineItems(RFQ $rfq): array
    {
        /** @var list<RfqLineItem> */
        return $this->lineItemRepository->findByRfq($rfq);
    }
    
    /**
     * Get version history for an RFQ
     *
     * @return list<array<string, mixed>>
     */
    public function getVersionHistory(RFQ $rfq): array
    {
        /** @var list<array<string, mixed>> */
        return $this->versionRepository->getVersionHistory($rfq);
    }
    
    /**
     * Get full versions with snapshots
     *
     * @return list<RfqVersion>
     */
    public function getVersions(RFQ $rfq): array
    {
        /** @var list<RfqVersion> */
        return $this->versionRepository->findByRfq($rfq);
    }
    
    // Private helpers
    
    /**
     * @return list<LineItemSnapshot>
     */
    private function captureLineItems(RFQ $rfq): array
    {
        /** @var list<RfqLineItem> $lineItems */
        $lineItems = $this->lineItemRepository->findByRfq($rfq);

        return array_map(function (RfqLineItem $item): array {
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
        // The two-letter scheme mathematically caps at 26*26 = 676 revisions;
        // chr() would raise a ValueError beyond that, so the documented
        // capacity is enforced explicitly.
        if ($versionNumber < 1 || $versionNumber > 676) {
            throw new \OutOfRangeException(sprintf(
                'RFQ revision codes support version numbers 1-676, got %d',
                $versionNumber
            ));
        }

        if ($versionNumber <= 26) {
            return chr(64 + $versionNumber); // A=1, B=2, etc.
        }

        $first = intdiv($versionNumber - 1, 26);
        $second = (($versionNumber - 1) % 26) + 1;
        // The 1-676 capacity check bounds $first to 1..25; intdiv ranges are
        // not statically derivable, hence the identity clamp for analysis.
        $first = max(1, min(25, $first));

        return chr(64 + $first) . chr(64 + $second);
    }
    
    /**
     * @param list<LineItemSnapshot> $oldItems
     * @param list<LineItemSnapshot> $newItems
     * @return list<LineItemChange>
     */
    private function compareLineItems(array $oldItems, array $newItems): array
    {
        $changes = [];

        // Index by line number
        $oldByLine = [];
        foreach ($oldItems as $item) {
            if ($item['lineNumber'] === null) {
                continue; // unnumbered snapshots cannot be diffed line-by-line
            }
            $oldByLine[$item['lineNumber']] = $item;
        }

        $newByLine = [];
        foreach ($newItems as $item) {
            if ($item['lineNumber'] === null) {
                continue;
            }
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
    
    /**
     * Apply request data onto a line item. Scalar values are coerced to the
     * setter types (mirroring weak casts); non-numeric input for numeric
     * fields degrades to null instead of raising a TypeError.
     *
     * @param array<string|int, mixed> $data
     */
    private function applyLineItemData(RfqLineItem $lineItem, array $data, bool $includeStatus = false): void
    {
        if (isset($data['partNumber'])) $lineItem->setPartNumber($this->nullableString($data['partNumber']));
        if (isset($data['customerPartNumber'])) $lineItem->setCustomerPartNumber($this->nullableString($data['customerPartNumber']));
        if (isset($data['description'])) $lineItem->setDescription($this->nullableString($data['description']));
        if (isset($data['quantityAnnual'])) $lineItem->setQuantityAnnual($this->nullableInt($data['quantityAnnual']));
        if (isset($data['quantityPerBatch'])) $lineItem->setQuantityPerBatch($this->nullableInt($data['quantityPerBatch']));
        if (isset($data['unitPrice'])) $lineItem->setUnitPrice($this->nullableString($data['unitPrice']));
        if (isset($data['nrePrice'])) $lineItem->setNrePrice($this->nullableString($data['nrePrice']));
        if (isset($data['currency'])) $lineItem->setCurrency($this->nullableString($data['currency']));
        if (isset($data['leadTimeDays'])) $lineItem->setLeadTimeDays($this->nullableInt($data['leadTimeDays']));
        if (isset($data['technology'])) $lineItem->setTechnology($this->nullableString($data['technology']));
        if (isset($data['componentCount'])) $lineItem->setComponentCount($this->nullableInt($data['componentCount']));
        if (isset($data['specifications'])) $lineItem->setSpecifications($this->nullableString($data['specifications']));
        if (isset($data['notes'])) $lineItem->setNotes($this->nullableString($data['notes']));
        if ($includeStatus && isset($data['status'])) $lineItem->setStatus($this->nullableString($data['status']));

        // Boolean flags
        if (isset($data['requiresXray'])) $lineItem->setRequiresXray(boolval($data['requiresXray']));
        if (isset($data['requiresAoi'])) $lineItem->setRequiresAoi(boolval($data['requiresAoi']));
        if (isset($data['requiresFunctionalTest'])) $lineItem->setRequiresFunctionalTest(boolval($data['requiresFunctionalTest']));
        if (isset($data['requiresConformalCoating'])) $lineItem->setRequiresConformalCoating(boolval($data['requiresConformalCoating']));
    }

    /**
     * Coerce a raw value to ?string (null passes through, scalars are
     * stringified, non-scalars degrade to null).
     */
    private function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return is_scalar($value) ? (string) $value : null;
    }

    /**
     * Coerce a raw value to ?int; non-numeric input degrades to null.
     */
    private function nullableInt(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
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
        /** @var list<RfqLineItem> $lineItems */
        $lineItems = $this->lineItemRepository->findByRfq($rfq);
        
        $lineNumber = 1;
        foreach ($lineItems as $item) {
            $item->setLineNumber($lineNumber++);
        }
        
        $this->entityManager->flush();
    }
}
