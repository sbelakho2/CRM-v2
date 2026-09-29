<?php

namespace App\Command;

use App\Entity\ComplianceDocument;
use App\Service\CompliancePackService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Non-destructive reconciliation of duplicate (company, document_key)
 * compliance rows, so the guarded UNIQUE constraint can finally be created.
 *
 * For every duplicate set the BEST row is kept (deterministic priority:
 * has file, provided, approved status, newest upload, most metadata) and
 * the LOSERS are preserved but neutralized: their document_key is cleared
 * (custom/legacy row) and required demoted. Nothing is deleted — file
 * versions, approvals, expiries and snooze state stay untouched on their
 * original rows.
 */
#[AsCommand(
    name: 'app:compliance:deduplicate-keys',
    description: 'Neutralize duplicate (company, document_key) compliance rows so the unique constraint can be created',
)]
class ComplianceDeduplicateKeysCommand extends Command
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Report duplicates without changing anything')
            ->addOption('install-unique', null, InputOption::VALUE_NONE, 'After reconciliation, create the unique index (fails if duplicates remain)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');

        $duplicateSets = $this->findDuplicateSets();

        if ($duplicateSets === []) {
            $io->success('No duplicate (company, document_key) rows.');
        } else {
            $io->note(sprintf('Found %d duplicate set(s).', count($duplicateSets)));

            foreach ($duplicateSets as $set) {
                $rows = $this->loadSet($set['company_id'], $set['document_key']);
                $winner = $this->pickWinner($rows);

                foreach ($rows as $row) {
                    if ($row->getId() === $winner->getId()) {
                        continue;
                    }

                    $io->writeln(sprintf(
                        '  company=%d key=%s: keep #%d (file=%s, provided=%s, status=%s); neutralize #%d',
                        $set['company_id'],
                        $set['document_key'],
                        $winner->getId(),
                        $winner->getFileName() !== null ? 'yes' : 'no',
                        $winner->isProvided() ? 'yes' : 'no',
                        $winner->getStatus() ?? '-',
                        $row->getId()
                    ));

                    if (!$dryRun) {
                        // Consolidate EVIDENCE onto the winner first: the
                        // best of each field across the duplicate set
                        // survives on one row, so nothing is lost by the
                        // neutralization.
                        $winner->setFileName($winner->getFileName() ?: $row->getFileName());
                        $winner->setProvided($winner->isProvided() || $row->isProvided());
                        if ($winner->getStatus() === null && $row->getStatus() !== null) {
                            $winner->setStatus($row->getStatus());
                        }
                        $winner->setExpiryDate($winner->getExpiryDate() ?: $row->getExpiryDate());
                        $winner->setUploadedAt($winner->getUploadedAt() ?: $row->getUploadedAt());
                        $winner->setSnoozedUntil($winner->getSnoozedUntil() ?: $row->getSnoozedUntil());

                        // Neutralize, never delete: the row stays queryable
                        // history; NULL document_key escapes the unique
                        // constraint (MySQL allows multiple NULLs).
                        $row->setDocumentKey(null);
                        $row->setRequired(false);
                    }
                }
            }

            if (!$dryRun) {
                $this->entityManager->flush();
                $io->success('Reconciliation persisted (losers keep all their data with a NULL key).');
            }
        }

        if ($input->getOption('install-unique')) {
            // Idempotent: the guarded migration may have created the index
            // already on a clean database.
            $indexExists = (bool) $this->entityManager->getConnection()->fetchOne(
                "SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'compliance_documents' AND INDEX_NAME = 'uniq_compliance_company_key'"
            );
            if ($indexExists) {
                $io->success('Unique index uniq_compliance_company_key already exists.');

                return Command::SUCCESS;
            }

            $remaining = $this->findDuplicateSets();
            if ($remaining !== []) {
                $io->error(sprintf('Cannot install unique index: %d duplicate set(s) remain.', count($remaining)));

                return Command::FAILURE;
            }

            $this->entityManager->getConnection()->executeStatement(
                'CREATE UNIQUE INDEX uniq_compliance_company_key ON compliance_documents (company_id, document_key)'
            );
            $io->success('Unique index uniq_compliance_company_key installed.');
        }

        return Command::SUCCESS;
    }

    /**
     * @return list<array{company_id: int, document_key: string}>
     */
    private function findDuplicateSets(): array
    {
        $rows = $this->entityManager->getConnection()->fetchAllAssociative(
            'SELECT company_id, document_key
             FROM compliance_documents
             WHERE document_key IS NOT NULL
             GROUP BY company_id, document_key
             HAVING COUNT(*) > 1
             ORDER BY company_id'
        );

        return array_map(
            static fn (array $r): array => ['company_id' => (int) $r['company_id'], 'document_key' => (string) $r['document_key']],
            $rows
        );
    }

    /**
     * @return list<ComplianceDocument>
     */
    private function loadSet(int $companyId, string $documentKey): array
    {
        return $this->entityManager->getRepository(ComplianceDocument::class)->createQueryBuilder('d')
            ->andWhere('d.company = :companyId')
            ->andWhere('d.documentKey = :key')
            ->setParameter('companyId', $companyId)
            ->setParameter('key', $documentKey)
            ->orderBy('d.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Deterministic, data-preserving priority: the row that carries the
     * most real compliance evidence wins.
     */
    private function pickWinner(array $rows): ComplianceDocument
    {
        usort($rows, function (ComplianceDocument $a, ComplianceDocument $b): int {
            return $this->score($b) <=> $this->score($a) ?: $a->getId() <=> $b->getId();
        });

        return $rows[0];
    }

    private function score(ComplianceDocument $d): int
    {
        $score = 0;
        if ($d->getFileName() !== null && $d->getFileName() !== '') {
            $score += 32;
        }
        if ($d->isProvided()) {
            $score += 16;
        }
        if ($d->getStatus() === ComplianceDocument::STATUS_APPROVED) {
            $score += 8;
        }
        if ($d->getExpiryDate() !== null) {
            $score += 4;
        }
        if ($d->getUploadedAt() !== null) {
            $score += 2;
        }
        if ($d->isRequired()) {
            $score += 1;
        }

        return $score;
    }
}
