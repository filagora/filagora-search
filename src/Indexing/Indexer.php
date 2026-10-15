<?php

declare(strict_types=1);

namespace Filagora\Search\Indexing;

use Closure;
use Filagora\Search\Contracts\GroupedSearchIndex;
use Filagora\Search\Contracts\SearchEngine;
use Filagora\Search\Contracts\SearchIndex;
use Filagora\Search\Contracts\WaitsForTasks;
use Filagora\Search\Engines\DatabaseEngine;
use Filagora\Search\Support\Document;
use Filagora\Search\Support\SearchEngines;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * Puts documents into the configured engine: one model after a save, or a whole index in chunks.
 * With the `database` engine there is nothing to keep, so every call does nothing.
 */
class Indexer
{
    public function __construct(private readonly SearchEngines $engines) {}

    public function engine(): SearchEngine
    {
        return $this->engines->engine();
    }

    /**
     * False for the database engine, which answers from the tables and stores nothing.
     */
    public function isActive(): bool
    {
        return ! $this->engine() instanceof DatabaseEngine;
    }

    /**
     * Index the model, or take it out if it should not be searchable (any more).
     */
    public function sync(SearchIndex $index, Model $model): void
    {
        if (! $this->isActive()) {
            return;
        }

        if ($index->shouldBeSearchable($model)) {
            $this->engine()->index($index->definition(), [$index->toDocument($model)]);
        } else {
            $this->engine()->remove($index->definition(), [$model->getKey()]);
        }
    }

    public function forget(SearchIndex $index, int|string $key): void
    {
        if ($this->isActive()) {
            $this->engine()->remove($index->definition(), [$key]);
        }
    }

    /**
     * Send every searchable row of the index, in key order, `$batchSize` at a time. `$fromId`
     * resumes at a key, `$limit` stops after that many rows have been looked at, `$progress` is
     * told the running total after each batch. Returns how many documents were sent.
     *
     * @param  Closure(int): void|null  $progress
     */
    public function reindex(SearchIndex $index, int|string|null $fromId = null, int $batchSize = 500, ?int $limit = null, int $sleepMs = 0, ?Closure $progress = null): int
    {
        return $this->send($index, $fromId, $batchSize, $limit, $sleepMs, $progress, static fn (Model $model): Document => $index->toDocument($model), false);
    }

    /**
     * Send only one group of fields of every searchable row, as partial updates.
     *
     * @param  Closure(int): void|null  $progress
     */
    public function backfill(GroupedSearchIndex $index, string $group, int|string|null $fromId = null, int $batchSize = 500, ?int $limit = null, int $sleepMs = 0, ?Closure $progress = null): int
    {
        if (! in_array($group, $index->groups(), true)) {
            throw new InvalidArgumentException("[{$group}] is not a field group of the [{$index->definition()->name}] index.");
        }

        return $this->send($index, $fromId, $batchSize, $limit, $sleepMs, $progress, static fn (Model $model): Document => $index->toPartialDocument($model, $group), true);
    }

    /**
     * @param  Closure(Model): Document  $toDocument
     * @param  Closure(int): void|null  $progress
     */
    private function send(SearchIndex $index, int|string|null $fromId, int $batchSize, ?int $limit, int $sleepMs, ?Closure $progress, Closure $toDocument, bool $partial): int
    {
        $query = $index->query();
        $sent = 0;
        $seen = 0;

        if ($fromId !== null) {
            $query->where($query->getModel()->getQualifiedKeyName(), '>=', $fromId);
        }

        $query->chunkById(max(1, $batchSize), function ($models) use ($index, $limit, $sleepMs, $progress, $toDocument, $partial, &$sent, &$seen): bool {
            $documents = [];

            foreach ($models as $model) {
                if ($limit !== null && $seen >= $limit) {
                    break;
                }

                $seen++;

                if ($index->shouldBeSearchable($model)) {
                    $documents[] = $toDocument($model);
                }
            }

            if ($documents !== []) {
                $partial
                    ? $this->engine()->update($index->definition(), $documents)
                    : $this->engine()->index($index->definition(), $documents);
                $sent += count($documents);
            }

            if ($progress !== null) {
                $progress($sent);
            }

            if ($sleepMs > 0) {
                usleep($sleepMs * 1000);
            }

            return $limit === null || $seen < $limit;
        });

        if ($this->engine() instanceof WaitsForTasks) {
            $this->engine()->waitForTasks();
        }

        return $sent;
    }
}
