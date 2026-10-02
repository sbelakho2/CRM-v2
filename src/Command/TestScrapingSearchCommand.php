<?php

namespace App\Command;

use App\Service\WebCrawler\SearchProvider\SearchProviderInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:test-scraping-search',
    description: 'Test the scraping-based search provider with a query',
)]
class TestScrapingSearchCommand extends Command
{
    public function __construct(
        private SearchProviderInterface $searchProvider
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('query', InputArgument::OPTIONAL, 'Search query', '"wire harness" manufacturer Germany IATF automotive -site:linkedin.com')
            ->addOption('region', 'r', InputOption::VALUE_OPTIONAL, 'Region code (DE, US, FR, etc.)', 'DE')
            ->addOption('max', 'm', InputOption::VALUE_OPTIONAL, 'Max results', 20);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        /** @var string $query */
        $query = $input->getArgument('query');
        /** @var string|null $region VALUE_OPTIONAL string option */
        $region = $input->getOption('region');
        $maxOption = $input->getOption('max');
        $maxResults = is_numeric($maxOption) ? (int) $maxOption : 20;

        $io->title('Scraping Search Provider Test');
        $io->section("Query: {$query}");
        $io->text("Region: {$region} | Max Results: {$maxResults}");

        $providerClass = get_class($this->searchProvider);
        $io->note("Active provider: {$providerClass}");

        $io->newLine();
        $io->text('Executing search...');

        $startTime = microtime(true);

        try {
            $resultSet = $this->searchProvider->search($query, $region, null, $maxResults, 1);
            $elapsed = round(microtime(true) - $startTime, 2);

            $results = $resultSet->getResults();

            $io->success("Search completed in {$elapsed}s — got " . count($results) . " results");

            if (empty($results)) {
                $io->warning('No results returned');
                return Command::SUCCESS;
            }

            $tableRows = [];
            foreach ($results as $i => $result) {
                $domain = parse_url($result->getUrl(), PHP_URL_HOST) ?? $result->getDisplayLink();
                $title = mb_substr($result->getTitle(), 0, 50);
                $snippet = mb_substr($result->getSnippet(), 0, 60);

                $tableRows[] = [
                    $i + 1,
                    $domain,
                    $title,
                    $snippet . '...',
                ];
            }

            $io->table(
                ['#', 'Domain', 'Title', 'Snippet'],
                $tableRows
            );

            // Show first 3 full results
            $io->section('First 3 Results (full details)');
            foreach (array_slice($results, 0, 3) as $i => $result) {
                $io->writeln("<info>" . ($i + 1) . ". {$result->getTitle()}</info>");
                $io->writeln("   URL: {$result->getUrl()}");
                $io->writeln("   Domain: {$result->getDisplayLink()}");
                $io->writeln("   Snippet: " . mb_substr($result->getSnippet(), 0, 150));
                $io->newLine();
            }

            return Command::SUCCESS;

        } catch (\Throwable $e) {
            $io->error("Search failed: {$e->getMessage()}");
            $io->writeln("<comment>Stack trace:</comment>");
            $io->writeln($e->getTraceAsString());
            return Command::FAILURE;
        }
    }
}
