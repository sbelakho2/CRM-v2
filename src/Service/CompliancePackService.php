<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Company;
use App\Entity\ComplianceDocument;
use App\Repository\ComplianceDocumentRepository;
use Doctrine\ORM\EntityManagerInterface;

class CompliancePackService
{
    private const REQUIRED_DOCUMENTS = [
        'Certificate of Incorporation',
        'Tax Registration Certificate',
        'ISO 9001',
        'ISO 14001',
        'ISO 45001',
        'Financial Statements (2 years)',
        'Bank Reference Letter',
        'Insurance Certificate',
        'Company Profile / Brochure',
        'Quality Manual',
        'Process Flow Charts',
        'Equipment List',
        'Technical Capabilities Statement',
        'Customer Reference List',
        'NDA Template',
        'Terms & Conditions',
        'Conflict Minerals Policy',
        'Export Control Compliance',
        'REACH Compliance',
        'RoHS Compliance',
    ];

    public function __construct(
        private EntityManagerInterface $entityManager,
        private ComplianceDocumentRepository $documentRepository
    ) {}

    /**
     * Get all required document types
     */
    public function getRequiredDocuments(): array
    {
        return self::REQUIRED_DOCUMENTS;
    }

    /**
     * Get company's compliance pack status
     */
    public function getCompanyPackStatus(Company $company): array
    {
        $documents = $this->documentRepository->findBy(['company' => $company]);
        $uploaded = [];

        // A checklist row existing is NOT a document being uploaded: rows
        // are created empty by pack initialization. Only rows with actual
        // file bytes count as uploaded.
        foreach ($documents as $doc) {
            if (!$doc->isProvided() || $doc->getFileName() === null || $doc->getFileName() === '') {
                continue;
            }
            $uploaded[$doc->getDocumentType()] = [
                'uploaded' => true,
                'fileName' => $doc->getFileName(),
                'uploadedAt' => $doc->getUploadedAt(),
            ];
        }

        $status = [];
        foreach (self::REQUIRED_DOCUMENTS as $docType) {
            $status[$docType] = $uploaded[$docType] ?? ['uploaded' => false];
        }

        return $status;
    }

    /**
     * Calculate completion percentage
     *
     * Only REQUIRED document types count toward the percentage — uploaded
     * documents outside the required list (or duplicates of the same type)
     * must not push the result past 100%.
     */
    public function getCompletionPercentage(Company $company): float
    {
        $total = count(self::REQUIRED_DOCUMENTS);
        
        $documents = $this->documentRepository->findBy(['company' => $company]);
        $uploadedRequiredTypes = [];
        foreach ($documents as $doc) {
            if (!$doc->isProvided() || $doc->getFileName() === null || $doc->getFileName() === '') {
                continue;
            }
            $type = $doc->getDocumentType() ?? $doc->getName();
            if (in_array($type, self::REQUIRED_DOCUMENTS, true)) {
                $uploadedRequiredTypes[$type] = true;
            }
        }
        
        $pct = $total > 0 ? (count($uploadedRequiredTypes) / $total) * 100 : 0;
        
        return round(min(100.0, $pct), 1);
    }

    /**
     * Get missing documents for company
     */
    public function getMissingDocuments(Company $company): array
    {
        $documents = $this->documentRepository->findBy(['company' => $company]);
        $uploadedTypes = [];
        
        foreach ($documents as $doc) {
            // Only actually-provided documents can satisfy a requirement —
            // empty checklist rows must surface as missing.
            if (!$doc->isProvided() || $doc->getFileName() === null || $doc->getFileName() === '') {
                continue;
            }
            // Document type may be stored on either field depending on how the
            // document was created — consider both so required types aren't
            // falsely reported as missing.
            $type = $doc->getDocumentType() ?? $doc->getName();
            if ($type !== null) {
                $uploadedTypes[] = $type;
            }
        }

        return array_diff(self::REQUIRED_DOCUMENTS, $uploadedTypes);
    }

    /**
     * Check if company has complete compliance pack
     */
    public function hasCompleteCompliancePack(Company $company): bool
    {
        return count($this->getMissingDocuments($company)) === 0;
    }

    /**
     * Get sector-specific required documents
     */
    public function getSectorSpecificDocuments(string $sector): array
    {
        $base = self::REQUIRED_DOCUMENTS;
        
        $sectorSpecific = match($sector) {
            'Automotive' => ['APQP Documentation', 'PPAP Requirements', 'Automotive Quality System'],
            'Aerospace' => ['AS9100', 'NADCAP Certification', 'First Article Inspection'],
            'Rail' => ['IRIS Certification', 'EN 15085', 'Railway Product Certification'],
            'Renewables' => ['IEC 61215', 'IEC 61730', 'Environmental Impact Assessment'],
            'Power Electronics' => ['IEC 61508', 'UL Certification', 'EMC Compliance'],
            'Industrial' => ['CE Marking', 'ATEX Certification'],
            'Defense' => ['ITAR Compliance', 'MIL-STD-810', 'NIST 800-171', 'DD254'],
            'Medical' => ['ISO 13485', 'FDA 21 CFR 820', 'MDR Compliance'],
            'Telecom' => ['TL 9000', 'NEBS GR-63/78', 'FCC Part 15'],
            'Marine' => ['DNV GL Certification', 'IEC 60092', 'Marine Type Approval'],
            'HVAC' => ['AHRI Certification', 'UL 1995', 'ASHRAE Standards'],
            'Consumer Electronics' => ['FCC Certification', 'UL/CSA', 'RoHS/REACH'],
            'Data Center' => ['TIA-942', 'ASHRAE TC 9.9', 'Energy Star'],
            'Energy Storage' => ['UL 1973', 'IEC 62619', 'UN 38.3'],
            default => [],
        };

        return array_merge($base, $sectorSpecific);
    }

    /**
     * Create document record (file upload handled by VichUploader)
     */
    public function createDocument(Company $company, string $documentType, string $fileName): ComplianceDocument
    {
        $document = new ComplianceDocument();
        $document->setCompany($company);
        $document->setName($documentType);
        $document->setDocumentKey(self::documentKeyFromName($documentType));
        $document->setFileName($fileName);
        $document->setUploadedAt(new \DateTime());

        $this->entityManager->persist($document);
        $this->entityManager->flush();

        return $document;
    }

    /**
     * Retire a compliance document from the active checklist.
     *
     * Rows (and their version history) are never physically deleted: the
     * record is demoted instead, preserving file and approval history.
     */
    public function deleteDocument(ComplianceDocument $document): void
    {
        $document->setRequired(false);
        $document->setProvided(false);
        $this->entityManager->flush();
    }

    /**
     * Get recently uploaded documents
     */
    public function getRecentUploads(int $limit = 10): array
    {
        return $this->documentRepository->findBy(
            [],
            ['uploadedAt' => 'DESC'],
            $limit
        );
    }

    /**
     * Initialize (or reconcile) the compliance pack for a company.
     *
     * This operation is idempotent and NEVER destructive: existing
     * ComplianceDocument rows, uploaded files, expiry dates and approval
     * status are preserved. Rows are matched by documentKey (falling back to
     * a name match for legacy rows created before documentKey existed).
     * Rows that are no longer part of the standard pack are demoted with
     * required=false instead of being deleted.
     */
    public function initializeCompliancePackForCompany(Company $company): void
    {
        $sector = $company->getSector() ?? 'Industrial';
        $requiredDocs = $this->getSectorSpecificDocuments($sector);
        $requiredKeys = [];

        foreach ($requiredDocs as $docType) {
            $key = self::documentKeyFromName($docType);
            $requiredKeys[$key] = true;

            $document = $this->documentRepository->findOneBy([
                'company' => $company,
                'documentKey' => $key,
            ]);

            // Legacy rows predating documentKey are matched by name and
            // upgraded in place, keeping their uploaded files and history.
            if ($document === null) {
                $document = $this->documentRepository->findOneBy([
                    'company' => $company,
                    'name' => $docType,
                ]);
            }

            if ($document === null) {
                $document = new ComplianceDocument();
                $document->setCompany($company);
                $document->setName($docType);
                $document->setStatus('Pending');
                $this->entityManager->persist($document);
            }

            // Every reconciled row carries its stable key — including rows
            // created above (a bare elseif would leave new rows NULL-keyed
            // until some later reconciliation).
            if ($document->getDocumentKey() === null) {
                $document->setDocumentKey($key);
            }

            // Keep the human-readable label current; never touch file data,
            // expiry, uploadedAt or status of existing rows.
            if ($document->getName() !== $docType) {
                $document->setName($docType);
            }
        }

        // Demote (do NOT delete) rows that are no longer part of the pack.
        foreach ($this->documentRepository->findBy(['company' => $company]) as $document) {
            $key = $document->getDocumentKey();
            if ($key === null) {
                continue; // custom/manually-created document — leave untouched
            }
            if (!isset($requiredKeys[$key]) && $document->isRequired()) {
                $document->setRequired(false);
            }
        }

        $this->entityManager->flush();
    }

    /**
     * Stable machine key for a required-document definition, e.g.
     * "ISO 9001" -> "iso_9001". Used for reconciliation across pack
     * regenerations so history is never duplicated or destroyed.
     */
    public static function documentKeyFromName(string $name): string
    {
        $key = strtolower(trim($name));
        $key = preg_replace('/[^a-z0-9]+/', '_', $key) ?? '';
        $key = trim($key, '_');

        if ($key === '') {
            throw new \InvalidArgumentException('Document name cannot produce an empty key.');
        }

        return substr($key, 0, 100);
    }

    /**
     * Get compliance statistics for company
     */
    public function getComplianceStats(Company $company): array
    {
        $allDocs = $this->documentRepository->findBy(['company' => $company]);
        $total = count($allDocs);
        $uploaded = 0;
        $approved = 0;
        $pending = 0;
        $expired = 0;

        foreach ($allDocs as $doc) {
            if ($doc->isProvided() && $doc->getFileName()) {
                $uploaded++;
            }
            if ($doc->getStatus() === 'Approved') {
                $approved++;
            }
            if ($doc->getStatus() === 'Pending') {
                $pending++;
            }
            if ($doc->getExpiryDate() && $doc->getExpiryDate() < new \DateTime()) {
                $expired++;
            }
        }

        return [
            'total' => $total,
            'uploaded' => $uploaded,
            'approved' => $approved,
            'pending' => $pending,
            'expired' => $expired,
            'completion_percentage' => $total > 0 ? round(min(100.0, ($uploaded / $total) * 100), 1) : 0,
        ];
    }
}
