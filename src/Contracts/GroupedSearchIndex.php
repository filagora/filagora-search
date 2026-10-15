<?php

declare(strict_types=1);

namespace Filagora\Search\Contracts;

use Filagora\Search\Support\Document;
use Illuminate\Database\Eloquent\Model;

/**
 * An index whose documents have named groups of fields that can be sent on their own, so a new
 * filter field can be back-filled without recomputing the expensive ones (such as embeddings).
 */
interface GroupedSearchIndex extends SearchIndex
{
    /**
     * @return list<string>
     */
    public function groups(): array;

    /**
     * Only the fields of one group, for a partial update.
     */
    public function toPartialDocument(Model $model, string $group): Document;
}
