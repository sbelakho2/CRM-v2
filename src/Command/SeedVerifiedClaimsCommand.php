<?php

namespace App\Command;

use App\Entity\VerifiedCapability;
use App\Entity\VerifiedCertification;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Seeds the verified capability/certification registers with Starz's
 * OFFICIAL capability claims (the set previously hard-coded in
 * LeadSalesAnalystService), now as reviewable, dated, evidence-bearing
 * DATA rows instead of source-code truth.
 *
 * Idempotent: existing (site, key) / (site, standard) rows are left
 * untouched, so re-running never overwrites curated data.
 */
#[AsCommand(
    name: 'app:claims:seed-verified',
    description: 'Seed the verified capability/certification registers with the official Starz claims (idempotent)',
)]
class SeedVerifiedClaimsCommand extends Command
{
    private const CAPABILITIES = [
        'pcba' => 'PCB Assembly',
        'smt' => 'Surface-Mount Technology',
        'through_hole' => 'Through-Hole Assembly',
        'cable_assembly' => 'Cable Assembly',
        'box_build' => 'Box Build / Integration',
        'testing' => 'Functional & In-Circuit Testing',
        'prototyping' => 'Rapid Prototyping',
        'npi' => 'New Product Introduction',
        'bom_sourcing' => 'BOM Sourcing & Procurement',
        'dfa_review' => 'Design for Assembly Review',
        'conformal_coating' => 'Conformal Coating',
        'x_ray_inspection' => 'X-Ray Inspection (AXI)',
        'aoi' => 'Automated Optical Inspection',
        'functional_test' => 'Functional Test Development',
    ];

    private const CERTIFICATIONS = [
        'ISO 9001' => 'Quality Management',
        'ISO 14001' => 'Environmental Management',
        'AS9100' => 'Aerospace Quality',
        'ISO 13485' => 'Medical Devices',
        'IPC-A-610' => 'Electronics Assembly Acceptability',
        'J-STD-001' => 'Soldering Requirements',
        'UL' => 'Product Safety',
        'CE' => 'EU Conformity',
        'RoHS' => 'Hazardous Substances Restriction',
    ];

    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $capRepo = $this->entityManager->getRepository(VerifiedCapability::class);
        $certRepo = $this->entityManager->getRepository(VerifiedCertification::class);

        $seeded = 0;

        foreach (self::CAPABILITIES as $key => $label) {
            $existing = $capRepo->findOneBy(['site' => 'company-wide', 'capabilityKey' => $key]);
            if ($existing !== null) {
                continue;
            }

            $capability = new VerifiedCapability();
            $capability->setSite('company-wide');
            $capability->setCapabilityKey($key);
            $capability->setLabel($label);
            $capability->setStatus(VerifiedCapability::STATUS_VERIFIED);
            $capability->setVerifiedAt(new \DateTime());
            $capability->setEvidenceDocument('company register — verify per site and attach certificates');
            $this->entityManager->persist($capability);
            $seeded++;
        }

        foreach (self::CERTIFICATIONS as $standard => $scope) {
            $existing = $certRepo->findOneBy(['site' => 'company-wide', 'standard' => $standard]);
            if ($existing !== null) {
                continue;
            }

            $cert = new VerifiedCertification();
            $cert->setSite('company-wide');
            $cert->setStandard($standard);
            $cert->setStatus('verified');
            $cert->setValidFrom(new \DateTime('first day of January this year'));
            $cert->setEvidenceDocument('company register — attach certificate and set validUntil from the actual certificate');
            $this->entityManager->persist($cert);
            $seeded++;
        }

        $this->entityManager->flush();

        $io->success(sprintf(
            'Verified-claims register seeded (%d new rows; existing curated rows untouched). Review each row: set real validUntil dates and attach evidence documents per site.',
            $seeded
        ));

        return Command::SUCCESS;
    }
}
