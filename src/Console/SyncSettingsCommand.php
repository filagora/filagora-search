<?php

declare(strict_types=1);

namespace Filagora\Search\Console;

use Filagora\Search\Console\Concerns\ResolvesIndexes;
use Filagora\Search\Indexing\Indexer;
use Filagora\Search\Support\SearchIndexRegistry;
use Illuminate\Console\Command;

/**
 * Applies each index's searchable, filterable and sortable fields and synonyms to the engine.
 */
final class SyncSettingsCommand extends Command
{
    use ResolvesIndexes;

    protected $signature = 'filagora:search:sync-settings {--index=* : Index names (default: all)}';

    protected $description = 'Apply the search index settings to the engine.';

    public function handle(Indexer $indexer, SearchIndexRegistry $registry): int
    {
        if (! $indexer->isActive()) {
            $this->info('The database engine has no settings to apply.');

            return self::SUCCESS;
        }

        $indexes = $this->selectedIndexes($registry);

        if ($indexes === null) {
            return self::FAILURE;
        }

        foreach ($indexes as $name => $index) {
            $indexer->engine()->syncSettings($index->definition());
            $this->info("Index `{$name}`: settings applied.");
        }

        return self::SUCCESS;
    }
}
