<?php

declare(strict_types=1);

namespace Filagora\Search\Engines;

use Filagora\Search\Contracts\SearchEngine;
use Filagora\Search\Support\IndexDefinition;
use Filagora\Search\Support\IndexStats;
use Filagora\Search\Support\SearchIndexRegistry;
use Filagora\Search\Support\SearchQuery;
use Filagora\Search\Support\SearchResult;

/**
 * No separate store: every search is answered by the index's own database fallback, and indexing
 * does nothing. The default, and what a site with no search server uses.
 */
final class DatabaseEngine implements SearchEngine
{
    public function __construct(private readonly SearchIndexRegistry $indexes) {}

    public function name(): string
    {
        return 'database';
    }

    public function syncSettings(IndexDefinition $index): void {}

    public function index(IndexDefinition $index, iterable $documents): void {}

    public function remove(IndexDefinition $index, iterable $ids): void {}

    public function flush(IndexDefinition $index): void {}

    public function search(IndexDefinition $index, SearchQuery $query): SearchResult
    {
        return $this->indexes->get($index->name)->fallback($query);
    }

    public function stats(IndexDefinition $index): ?IndexStats
    {
        return null;
    }

    public function capabilities(): array
    {
        return [];
    }
}
