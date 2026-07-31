<?php

namespace App\Command;

use App\Service\LiveFxRateFetcher;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Lock\LockFactory;

/**
 * Fetch live FX rates from central bank APIs
 * 
 * Run manually: bin/console app:fx-rates:fetch
 * Run via cron: 0 * * * * /path/to/php bin/console app:fx-rates:fetch --quiet
 * 
 * Recommended to run hourly during business hours.
 */
#[AsCommand(
    name: 'app:fx-rates:fetch',
    description: 'Fetch latest FX rates from ECB, BOE, and other central bank APIs',
)]
class FetchFxRatesCommand extends Command
{
    public function __construct(
        private LiveFxRateFetcher $fxRateFetcher,
        private LockFactory $lockFactory,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('check', null, InputOption::VALUE_NONE, 'Only check rate freshness without fetching')
            ->addOption('single', 's', InputOption::VALUE_REQUIRED, 'Fetch single currency pair (e.g., EUR/USD)')
            ->setHelp(<<<'HELP'
The <info>%command.name%</info> command fetches the latest FX rates from official central bank APIs.

Sources used:
  - ECB (European Central Bank) via Frankfurter.app
  - ExchangeRate-API for additional currencies
  - Bank of England for GBP pairs

Run hourly via cron for fresh rates:
  <info>0 * * * * /path/to/php bin/console %command.name% --quiet</info>

Check current rate freshness:
  <info>php bin/console %command.name% --check</info>

Fetch single pair:
  <info>php bin/console %command.name% --single=EUR/USD</info>
HELP
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $lock = $this->lockFactory->createLock('fetch_fx_rates', 1800);
        $lock->acquire();

        // Check-only mode
        if ($input->getOption('check')) {
            return $this->checkFreshness($io);
        }
        
        // Single pair mode
        if ($pair = $input->getOption('single')) {
            return $this->fetchSinglePair($io, $pair);
        }
        
        // Full fetch
        $io->title('Fetching FX Rates from Central Banks');
        
        $io->section('Sources');
        $io->listing([
            'ECB (European Central Bank) via Frankfurter.app',
            'ExchangeRate-API (free tier)',
            'Bank of England (GBP pairs)',
        ]);
        
        $results = $this->fxRateFetcher->fetchAllRates();
        
        if (!empty($results['success'])) {
            $io->success(sprintf('Successfully fetched %d currency pairs', count($results['success'])));
            
            if ($output->isVerbose()) {
                $io->table(['Currency Pair'], array_map(fn($p) => [$p], $results['success']));
            }
        }
        
        if (!empty($results['failed'])) {
            $io->warning(sprintf('%d currency pairs failed', count($results['failed'])));
            
            foreach ($results['failed'] as $failure) {
                $io->writeln("  - <fg=yellow>{$failure}</>");
            }
        }
        
        // Show current freshness
        $io->section('Current Rate Freshness');
        $freshness = $this->fxRateFetcher->checkRateFreshness();
        
        $rows = [];
        $staleCount = 0;
        foreach ($freshness as $pair => $info) {
            $staleLabel = $info['stale'] ? '<fg=red>STALE</>' : '<fg=green>OK</>';
            $rows[] = [
                $pair,
                number_format($info['rate'], 6),
                $info['asof'],
                $info['age_hours'] . 'h',
                $staleLabel,
            ];
            if ($info['stale']) $staleCount++;
        }
        
        $io->table(['Pair', 'Rate', 'As Of', 'Age', 'Status'], $rows);
        
        if ($staleCount > 0) {
            $io->warning("{$staleCount} rate(s) are stale (>24h old)");
        }
        
        return Command::SUCCESS;
    }

    private function checkFreshness(SymfonyStyle $io): int
    {
        $io->title('FX Rate Freshness Check');
        
        $freshness = $this->fxRateFetcher->checkRateFreshness();
        
        if (empty($freshness)) {
            $io->warning('No FX rates stored in database. Run without --check to fetch rates.');
            return Command::FAILURE;
        }
        
        $rows = [];
        $staleCount = 0;
        foreach ($freshness as $pair => $info) {
            $staleLabel = $info['stale'] ? '<fg=red>STALE</>' : '<fg=green>OK</>';
            $rows[] = [
                $pair,
                number_format($info['rate'], 6),
                $info['asof'],
                $info['age_hours'] . 'h',
                $staleLabel,
            ];
            if ($info['stale']) $staleCount++;
        }
        
        $io->table(['Pair', 'Rate', 'As Of', 'Age', 'Status'], $rows);
        
        if ($staleCount > 0) {
            $io->error("{$staleCount} rate(s) are stale. Run 'bin/console app:fx-rates:fetch' to update.");
            return Command::FAILURE;
        }
        
        $io->success('All rates are fresh');
        return Command::SUCCESS;
    }

    private function fetchSinglePair(SymfonyStyle $io, string $pair): int
    {
        $parts = explode('/', strtoupper($pair));
        
        if (count($parts) !== 2) {
            $io->error('Invalid pair format. Use FROM/TO (e.g., EUR/USD)');
            return Command::FAILURE;
        }
        
        [$from, $to] = $parts;
        
        $io->title("Fetching Live Rate: {$from}/{$to}");
        
        $result = $this->fxRateFetcher->getLiveRate($from, $to);
        
        if (!$result) {
            $io->error("Could not fetch rate for {$from}/{$to}");
            return Command::FAILURE;
        }
        
        $io->definitionList(
            ['From Currency' => $from],
            ['To Currency' => $to],
            ['Rate' => number_format($result['rate'], 6)],
            ['Source' => $result['source']],
            ['Timestamp' => $result['timestamp']->format('Y-m-d H:i:s')],
            ['Example' => "1 {$from} = " . number_format($result['rate'], 4) . " {$to}"],
        );
        
        $io->success("Rate fetched successfully from {$result['source']}");
        
        return Command::SUCCESS;
    }
}
