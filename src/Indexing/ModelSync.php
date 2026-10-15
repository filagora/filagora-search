<?php

declare(strict_types=1);

namespace Filagora\Search\Indexing;

use Filagora\Search\Jobs\SyncSearchDocument;
use Filagora\Search\Support\SearchIndexRegistry;
use Illuminate\Database\Eloquent\Model;

/**
 * Listens to every Eloquent save, delete and restore and, for a model that belongs to a registered
 * index, queues a sync of its document. Listening to all models (not one class per index) is what
 * keeps a swapped model subclass indexed.
 */
final class ModelSync
{
    public function __construct(private readonly SearchIndexRegistry $indexes, private readonly Indexer $indexer) {}

    /**
     * @param  array<int, mixed>  $payload
     */
    public function handle(string $event, array $payload): void
    {
        $model = $payload[0] ?? null;

        if (! $model instanceof Model || $this->indexes->all() === [] || ! $this->indexer->isActive()) {
            return;
        }

        foreach ($this->indexes->all() as $name => $index) {
            if (! $model instanceof ($index->model())) {
                continue;
            }

            $pending = SyncSearchDocument::dispatch($name, $model->getKey());

            if (! config('filagora-search.queue', false)) {
                $pending->onConnection('sync');
            }

            if (config('filagora-search.after_commit', true)) {
                $pending->afterCommit();
            }
        }
    }
}
