<?php

declare(strict_types=1);

namespace Filagora\Search\Console;

use Filagora\Search\Console\Concerns\ResolvesIndexes;
use Filagora\Search\Indexing\HealthChecker;
use Filagora\Search\Indexing\Indexer;
use Filagora\Search\Support\SearchIndexRegistry;
use Illuminate\Console\Command;

/**
 * Compares each index with what it should hold and reports recent failed indexing tasks. Fails
 * (non-zero) when it finds a problem, so it can sit in a scheduler or a monitor.
 */
final class CheckHealthCommand extends Command
{
    use ResolvesIndexes;

    protected $signature = 'filagora:search:check-health {--index=* : Index names (default: all)}';

    protected $description = 'Compare each search index with its expected document count and look for failed indexing tasks.';

    public function handle(Indexer $indexer, SearchIndexRegistry $registry, HealthChecker $health): int
    {
        if (! $indexer->isActive()) {
            $this->info('The database engine stores nothing; nothing to check.');

            return self::SUCCESS;
        }

        $indexes = $this->selectedIndexes($registry);

        if ($indexes === null) {
            return self::FAILURE;
        }

        $problems = $health->problems(array_keys($indexes));

        if ($problems === []) {
            $this->info('All search indexes are healthy.');

            return self::SUCCESS;
        }

        foreach ($problems as $problem) {
            $this->error($problem);
        }

        return self::FAILURE;
    }
}
