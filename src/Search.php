<?php

declare(strict_types=1);

namespace Filagora\Search;

use Filagora\Search\Engines\DatabaseEngine;
use Filagora\Search\Support\CircuitBreaker;
use Filagora\Search\Support\SearchEngines;
use Filagora\Search\Support\SearchIndexRegistry;
use Filagora\Search\Support\SearchQuery;
use Filagora\Search\Support\SearchResult;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The entry point for searching. Asks the configured engine; if it throws, logs it, opens the
 * circuit and answers from the index's database fallback, and keeps doing so until the cooldown
 * ends. The database engine needs none of that and is called directly.
 */
class Search
{
    public function __construct(
        private readonly SearchEngines $engines,
        private readonly SearchIndexRegistry $indexes,
        private readonly CircuitBreaker $circuit,
    ) {}

    public function search(string $index, SearchQuery $query): SearchResult
    {
        $searchIndex = $this->indexes->get($index);
        $engine = $this->engines->engine();

        if ($engine instanceof DatabaseEngine) {
            return $engine->search($searchIndex->definition(), $query);
        }

        if ($this->circuit->isOpen()) {
            return $searchIndex->fallback($query);
        }

        try {
            return $engine->search($searchIndex->definition(), $query);
        } catch (Throwable $e) {
            Log::warning('Search engine failed; answering from the database.', ['engine' => $engine->name(), 'index' => $index, 'exception' => $e->getMessage()]);
            $this->circuit->trip();

            return $searchIndex->fallback($query);
        }
    }
}
