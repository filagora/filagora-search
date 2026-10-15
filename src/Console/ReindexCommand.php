<?php

declare(strict_types=1);

namespace Filagora\Search\Console;

use Filagora\Search\Console\Concerns\ResolvesIndexes;
use Filagora\Search\Indexing\Indexer;
use Filagora\Search\Support\SearchIndexRegistry;
use Illuminate\Console\Command;

/**
 * Sends every searchable row of the chosen indexes to the engine.
 */
final class ReindexCommand extends Command
{
    use ResolvesIndexes;

    protected $signature = 'filagora:search:reindex
        {--index=* : Index names (default: all)}
        {--flush : Remove every document first}
        {--sync-settings : Apply the index settings first}
        {--from-id= : Resume at this key (one index only)}
        {--batch-size= : Rows per batch (default from config)}
        {--sleep-ms=0 : Pause between batches}
        {--limit= : Stop each index after this many rows}';

    protected $description = 'Send every searchable row of the search indexes to the engine.';

    public function handle(Indexer $indexer, SearchIndexRegistry $registry): int
    {
        if (! $indexer->isActive()) {
            $this->info('The database engine stores nothing; there is nothing to reindex.');

            return self::SUCCESS;
        }

        $indexes = $this->selectedIndexes($registry);

        if ($indexes === null) {
            return self::FAILURE;
        }

        $fromId = $this->option('from-id');

        if ($fromId !== null && count($indexes) !== 1) {
            $this->error('--from-id needs exactly one --index.');

            return self::FAILURE;
        }

        foreach ($indexes as $name => $index) {
            if ($this->option('sync-settings')) {
                $indexer->engine()->syncSettings($index->definition());
            }

            if ($this->option('flush')) {
                $indexer->engine()->flush($index->definition());
            }

            $sent = $indexer->reindex(
                $index,
                $fromId === null ? null : (is_numeric($fromId) ? (int) $fromId : (string) $fromId),
                $this->option('batch-size') !== null ? (int) $this->option('batch-size') : (int) config('filagora-search.chunk_size', 500),
                $this->option('limit') !== null ? (int) $this->option('limit') : null,
                (int) $this->option('sleep-ms'),
            );

            $this->info("Index `{$name}`: sent {$sent} document(s).");
        }

        return self::SUCCESS;
    }
}
