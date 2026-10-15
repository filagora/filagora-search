<?php

declare(strict_types=1);

namespace Filagora\Search\Contracts;

use Filagora\Search\Enums\Capability;
use Filagora\Search\Support\Document;
use Filagora\Search\Support\IndexDefinition;
use Filagora\Search\Support\IndexStats;
use Filagora\Search\Support\SearchQuery;
use Filagora\Search\Support\SearchResult;

/**
 * Something that stores documents and finds them. Implement this to add an engine (Elasticsearch,
 * OpenSearch, Typesense) and run `Filagora\Search\Testing\EngineConformance` against it to prove
 * it behaves like the others.
 */
interface SearchEngine
{
    public function name(): string;

    /**
     * Create the index if needed and apply its searchable, filterable and sortable fields and synonyms.
     */
    public function syncSettings(IndexDefinition $index): void;

    /**
     * Add the documents, replacing any with the same id.
     *
     * @param  iterable<Document>  $documents
     */
    public function index(IndexDefinition $index, iterable $documents): void;

    /**
     * Merge these fields into the documents with these ids, keeping the fields not given; a
     * document that is not there yet is created from what is given.
     *
     * @param  iterable<Document>  $documents
     */
    public function update(IndexDefinition $index, iterable $documents): void;

    /**
     * @param  iterable<int|string>  $ids
     */
    public function remove(IndexDefinition $index, iterable $ids): void;

    /**
     * Remove every document, keeping the index and its settings.
     */
    public function flush(IndexDefinition $index): void;

    public function search(IndexDefinition $index, SearchQuery $query): SearchResult;

    /**
     * Null when the engine cannot say (the index does not exist yet, or it is unreachable).
     */
    public function stats(IndexDefinition $index): ?IndexStats;

    /**
     * @return list<Capability>
     */
    public function capabilities(): array;
}
