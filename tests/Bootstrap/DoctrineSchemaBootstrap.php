<?php

namespace App\Tests\Bootstrap;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;

final class DoctrineSchemaBootstrap
{
    private static bool $initialized = false;

    public static function ensureSchema(): void
    {
        if (self::$initialized) {
            return;
        }

        self::$initialized = true;

        $kernelClass = $_SERVER['KERNEL_CLASS'] ?? \App\Kernel::class;

        /** @var \Symfony\Component\HttpKernel\KernelInterface $kernel */
        $kernel = new $kernelClass('test', true);
        $kernel->boot();

        try {
            $container = $kernel->getContainer();
            /** @var EntityManagerInterface $entityManager */
            $entityManager = $container->get('doctrine')->getManager();

            TestDatabaseGuard::assertSafeTestDatabase($entityManager->getConnection());

            $allMetadata = $entityManager->getMetadataFactory()->getAllMetadata();
            if (count($allMetadata) === 0) {
                return;
            }

            $schemaTool = new SchemaTool($entityManager);

            try {
                $schemaTool->dropSchema($allMetadata);
                $schemaTool->createSchema($allMetadata);
            } catch (\Throwable $e) {
                // If drop+create fails (e.g. foreign key constraints prevent
                // full drop), fall back to updateSchema which handles existing
                // tables gracefully by issuing ALTER TABLE statements.
                try {
                    $schemaTool->updateSchema($allMetadata);
                } catch (\Throwable $inner) {
                    // Last resort: log and continue — tests may still work
                    // against the existing schema.
                    error_log('DoctrineSchemaBootstrap: ' . $inner->getMessage());
                }
            }
        } finally {
            $kernel->shutdown();
        }
    }
}
