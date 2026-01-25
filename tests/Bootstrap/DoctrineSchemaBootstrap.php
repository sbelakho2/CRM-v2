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

            $allMetadata = $entityManager->getMetadataFactory()->getAllMetadata();
            if (count($allMetadata) === 0) {
                return;
            }

            $schemaTool = new SchemaTool($entityManager);

            try {
                $schemaTool->dropSchema($allMetadata);
            } catch (\Throwable $e) {
                // Ignore drop failures; we primarily need createSchema to succeed.
            }

            $schemaTool->createSchema($allMetadata);
        } finally {
            $kernel->shutdown();
        }
    }
}
