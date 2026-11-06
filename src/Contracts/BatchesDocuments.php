<?php

declare(strict_types=1);

namespace Filagora\Search\Contracts;

use Filagora\Search\Support\Document;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

/**
 * An index that makes its documents for a whole chunk of rows at once, because one call for the
 * chunk is cheaper than one per row: a vector index asking an embedding service for every row's
 * vector together. A full reindex uses it; a single saved row still goes through `toDocument()`.
 */
interface BatchesDocuments
{
    /**
     * The documents for these rows, which have all passed `shouldBeSearchable()`.
     *
     * @param  Collection<int, Model>  $models
     * @return list<Document>
     */
    public function toDocuments(Collection $models): array;
}
