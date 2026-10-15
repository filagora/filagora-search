<?php

declare(strict_types=1);

namespace Filagora\Search\Contracts;

use Filagora\Search\Support\Document;
use Filagora\Search\Support\IndexDefinition;
use Filagora\Search\Support\SearchQuery;
use Filagora\Search\Support\SearchResult;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * What a package or host puts into search: one kind of thing, how it becomes a document, and how
 * to answer a query for it from the database when no engine can.
 */
interface SearchIndex
{
    public function definition(): IndexDefinition;

    /**
     * @return class-string<Model>
     */
    public function model(): string;

    public function shouldBeSearchable(Model $model): bool;

    public function toDocument(Model $model): Document;

    /**
     * Every row that may be searchable, for a full reindex (chunked by key).
     *
     * @return Builder<Model>
     */
    public function query(): Builder;

    /**
     * How many documents the index should hold, for the health check (a cheap count, not a scan).
     */
    public function expectedCount(): int;

    /**
     * The same query answered from the database, used when the engine is `database` or has failed.
     */
    public function fallback(SearchQuery $query): SearchResult;
}
