<?php

namespace App\Controller;

use App\Entity\TariffRate;
use App\Entity\FreightTable;
use App\Entity\FxRate;
use App\Service\DatasetImportService;
use App\Service\TrackerDataService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * AdminDatasetController
 * 
 * Dataset administration for tariff rates, freight tables, and FX rates.
 * 
 * Features:
 * - Dataset import from DMZ to LAN
 * - Version management with UUID versioning
 * - Snapshot and rollback capability
 * - SHA-256 signature verification
 * - Active version tracking
 * - Import history and audit trail
 * 
 * Routes:
 * - GET  /admin/datasets              - Dataset overview
 * - GET  /admin/datasets/import       - Import form
 * - POST /admin/datasets/import       - Upload and import dataset
 * - POST /admin/datasets/{id}/rollback - Rollback to previous version
 * - GET  /admin/datasets/history      - Import history
 */
#[Route('/admin/datasets')]
#[IsGranted('ROLE_ADMIN')]
class AdminDatasetController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private DatasetImportService $datasetImport,
        private TrackerDataService $trackerData
    ) {}

    /**
     * Dataset overview with version info
     */
    #[Route('', name: 'admin_dataset_index', methods: ['GET'])]
    public function index(): Response
    {
        $datasets = $this->trackerData->getDatasets();
        $stats = $this->trackerData->getStatistics();
        
        return $this->render('admin_dataset/index.html.twig', [
            'datasets' => $datasets,
            'stats' => $stats,
            'total_datasets' => count($datasets),
            'active_versions' => count($datasets),
            'last_updated' => $stats['last_updated'],
            'storage_used' => $stats['storage_used']
        ]);
    }

    /**
     * Show import form
     */
    #[Route('/import', name: 'admin_dataset_import_form', methods: ['GET'])]
    public function importForm(): Response
    {
        $datasetTypes = [
            'tariff_rate' => 'admin_dataset.types.tariff_rate',
            'freight_table' => 'admin_dataset.types.freight_table',
            'fx_rate' => 'admin_dataset.types.fx_rate'
        ];
        
        return $this->render('admin_dataset/import_form.html.twig', [
            'datasetTypes' => $datasetTypes,
            'maxFileSize' => '50MB',
            'pageTitle' => 'admin_dataset.import.title'
        ]);
    }

    /**
     * Upload and import dataset
     */
    #[Route('/import', name: 'admin_dataset_import', methods: ['POST'])]
    public function import(Request $request): Response
    {
        // 1. Validate file upload
        $datasetFile = $request->files->get('dataset_file');
        if (!$datasetFile) {
            $this->addFlash('error', 'admin_dataset.flash.error.upload_file');
            return $this->redirectToRoute('admin_dataset_import_form');
        }
        
        $allowedExtensions = ['csv', 'json', 'xml'];
        $extension = $datasetFile->getClientOriginalExtension();
        if (!in_array($extension, $allowedExtensions)) {
            $this->addFlash('error', 'admin_dataset.flash.error.invalid_format');
            return $this->redirectToRoute('admin_dataset_import_form');
        }
        
        // 2. Get import parameters
        $datasetType = $request->request->get('dataset_type', 'fx_rate');
        $signature = $request->request->get('signature');
        $createSnapshot = (bool) $request->request->get('create_snapshot', true);
        
        // 3. Validate dataset type
        $validTypes = ['tariff_rate', 'freight_table', 'fx_rate'];
        if (!in_array($datasetType, $validTypes)) {
            $this->addFlash('error', 'admin_dataset.flash.error.invalid_type');
            return $this->redirectToRoute('admin_dataset_import_form');
        }
        
        // 4. Create snapshot of current data (if requested)
        if ($createSnapshot) {
            try {
                $snapshotVersion = $this->datasetImport->snapshotDataset($datasetType, 'PRE_IMPORT');
                $this->addFlash('info', 'admin_dataset.flash.info.snapshot_created');
            } catch (\Exception $e) {
                $this->addFlash('warning', 'admin_dataset.flash.warning.snapshot_failed');
            }
        }
        
        // 5. Import dataset (currently only FX rates implemented)
        try {
            if ($datasetType === 'fx_rate') {
                $result = $this->datasetImport->importFxRates(
                    $datasetFile->getPathname(),
                    $signature
                );
            } else {
                // Placeholder for other dataset types
                $this->addFlash('warning', 'admin_dataset.flash.warning.not_implemented');
                return $this->redirectToRoute('admin_dataset_import_form');
            }
        } catch (\Exception $e) {
            $this->addFlash('error', 'admin_dataset.flash.error.import_failed');
            return $this->redirectToRoute('admin_dataset_import_form');
        }
        
        // 6. Display import summary
        $this->addFlash('success', 'admin_dataset.flash.success.imported');
        
        if (!empty($result['errors'])) {
            $this->addFlash('warning', 'admin_dataset.flash.warning.errors_encountered');
        }
        
        // 7. Redirect to overview
        return $this->redirectToRoute('admin_dataset_index');
    }

    /**
     * Rollback to previous dataset version
     */
    #[Route('/{datasetType}/rollback', name: 'admin_dataset_rollback', methods: ['POST'])]
    public function rollback(string $datasetType, Request $request): Response
    {
        // 1. Validate dataset type
        $validTypes = ['tariff_rate', 'freight_table', 'fx_rate'];
        if (!in_array($datasetType, $validTypes)) {
            $this->addFlash('error', 'admin_dataset.flash.error.invalid_type');
            return $this->redirectToRoute('admin_dataset_index');
        }
        
        // 2. Get target version
        $targetVersion = $request->request->get('target_version');
        if (!$targetVersion) {
            $this->addFlash('error', 'admin_dataset.flash.error.specify_version');
            return $this->redirectToRoute('admin_dataset_index');
        }
        
        // 3. Create snapshot of current data before rollback
        try {
            $snapshotVersion = $this->datasetImport->snapshotDataset($datasetType, 'PRE_ROLLBACK');
            $this->addFlash('info', 'admin_dataset.flash.info.snapshot_created');
        } catch (\Exception $e) {
            $this->addFlash('warning', 'admin_dataset.flash.warning.snapshot_failed');
            // Continue with rollback anyway
        }
        
        // 4. Perform rollback
        try {
            $result = $this->datasetImport->rollbackDataset(
                $targetVersion
            );
        } catch (\Exception $e) {
            $this->addFlash('error', 'admin_dataset.flash.error.rollback_failed');
            return $this->redirectToRoute('admin_dataset_index');
        }
        
        // 5. Display rollback summary
        $this->addFlash('success', 'admin_dataset.flash.success.rolled_back');
        
        // 6. Redirect to overview
        return $this->redirectToRoute('admin_dataset_index');
    }

    /**
     * Import history with version timeline
     */
    #[Route('/history', name: 'admin_dataset_history', methods: ['GET'])]
    public function history(Request $request): Response
    {
        // 1. Get optional dataset type filter
        $datasetType = $request->query->get('type');
        
        // 2. Build version history queries for each dataset type
        $tariffHistory = $this->entityManager->getRepository(TariffRate::class)
            ->createQueryBuilder('t')
            ->select('t.versionUuid', 't.importedAt', 'COUNT(t.id) as rowCount')
            ->where('t.versionUuid IS NOT NULL')
            ->groupBy('t.versionUuid', 't.importedAt')
            ->orderBy('t.importedAt', 'DESC')
            ->getQuery()
            ->getResult();
        
        $freightHistory = $this->entityManager->getRepository(FreightTable::class)
            ->createQueryBuilder('f')
            ->select('f.versionUuid', 'f.importedAt', 'COUNT(f.id) as rowCount')
            ->where('f.versionUuid IS NOT NULL')
            ->groupBy('f.versionUuid', 'f.importedAt')
            ->orderBy('f.importedAt', 'DESC')
            ->getQuery()
            ->getResult();
        
        $fxHistory = $this->entityManager->getRepository(FxRate::class)
            ->createQueryBuilder('fx')
            ->select('fx.versionUuid', 'fx.importedAt', 'COUNT(fx.id) as rowCount')
            ->where('fx.versionUuid IS NOT NULL')
            ->groupBy('fx.versionUuid', 'fx.importedAt')
            ->orderBy('fx.importedAt', 'DESC')
            ->getQuery()
            ->getResult();
        
        // 3. Combine all history records with type labels
        $combinedHistory = array_merge(
            array_map(fn($h) => array_merge($h, ['type' => 'tariff_rate']), $tariffHistory),
            array_map(fn($h) => array_merge($h, ['type' => 'freight_table']), $freightHistory),
            array_map(fn($h) => array_merge($h, ['type' => 'fx_rate']), $fxHistory)
        );
        
        // 4. Sort by import date descending
        usort($combinedHistory, fn($a, $b) => $b['importedAt'] <=> $a['importedAt']);
        
        // 5. Apply type filter if specified
        if ($datasetType) {
            $combinedHistory = array_filter(
                $combinedHistory,
                fn($h) => $h['type'] === $datasetType
            );
        }
        
        // 6. Render history template
        return $this->render('admin_dataset/history.html.twig', [
            'history' => $combinedHistory,
            'filterType' => $datasetType,
            'pageTitle' => 'admin_dataset.history.title'
        ]);
    }
}
