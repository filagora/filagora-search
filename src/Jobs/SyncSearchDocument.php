<?php

declare(strict_types=1);

namespace Filagora\Search\Jobs;

use Filagora\Search\Indexing\Indexer;
use Filagora\Search\Support\SearchIndexRegistry;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Brings one row's document up to date: indexes it, or removes it if the row is gone or should no
 * longer be searchable. A failure is logged, not thrown: results are checked against the database
 * when they are read, so a stale document does not show anything wrong, and a failing engine must
 * not break the save that triggered this.
 */
final class SyncSearchDocument implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public function __construct(public readonly string $index, public readonly int|string $key) {}

    public function handle(Indexer $indexer, SearchIndexRegistry $indexes): void
    {
        if (! $indexes->has($this->index)) {
            return;
        }

        $index = $indexes->get($this->index);

        try {
            $model = $index->model()::query()->withoutGlobalScopes()->find($this->key);

            $model === null ? $indexer->forget($index, $this->key) : $indexer->sync($index, $model);
        } catch (Throwable $e) {
            Log::warning('Search document sync failed.', ['index' => $this->index, 'key' => $this->key, 'exception' => $e->getMessage()]);
        }
    }
}
