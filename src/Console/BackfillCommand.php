<?php

declare(strict_types=1);

namespace Filagora\Search\Console;

use Filagora\Search\Contracts\GroupedSearchIndex;
use Filagora\Search\Indexing\Indexer;
use Filagora\Search\Support\SearchIndexRegistry;
use Illuminate\Console\Command;

/**
 * Sends one group of fields of every searchable row as a partial update, so a new filter or sort
 * field reaches an existing index without recomputing everything else.
 */
final class BackfillCommand extends Command
{
    protected $signature = 'filagora:search:backfill
        {index : Index name}
        {group : Field group name}
        {--from-id= : Resume at this key}
        {--batch-size= : Rows per batch (default from config)}
        {--sleep-ms=0 : Pause between batches}
        {--limit= : Stop after this many rows}';

    protected $description = 'Send one group of fields of every searchable row to the engine as a partial update.';

    public function handle(Indexer $indexer, SearchIndexRegistry $registry): int
    {
        if (! $indexer->isActive()) {
            $this->info('The database engine stores nothing; there is nothing to backfill.');

            return self::SUCCESS;
        }

        $name = (string) $this->argument('index');

        if (! $registry->has($name)) {
            $this->error("No search index is registered as [{$name}].");

            return self::FAILURE;
        }

        $index = $registry->get($name);

        if (! $index instanceof GroupedSearchIndex) {
            $this->error("Index [{$name}] has no field groups.");

            return self::FAILURE;
        }

        $group = (string) $this->argument('group');

        if (! in_array($group, $index->groups(), true)) {
            $this->error("[{$group}] is not a field group of [{$name}]. Groups: ".implode(', ', $index->groups()).'.');

            return self::FAILURE;
        }

        $fromId = $this->option('from-id');

        $sent = $indexer->backfill(
            $index,
            $group,
            $fromId === null ? null : (is_numeric($fromId) ? (int) $fromId : (string) $fromId),
            $this->option('batch-size') !== null ? (int) $this->option('batch-size') : (int) config('filagora-search.chunk_size', 500),
            $this->option('limit') !== null ? (int) $this->option('limit') : null,
            (int) $this->option('sleep-ms'),
        );

        $this->info("Index `{$name}`: updated {$sent} document(s) with group `{$group}`.");

        return self::SUCCESS;
    }
}
