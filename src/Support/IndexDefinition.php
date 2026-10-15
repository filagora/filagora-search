<?php

declare(strict_types=1);

namespace Filagora\Search\Support;

/**
 * What an index holds and how it may be queried. `searchable` is ordered: a match in an earlier
 * field ranks above one in a later field.
 */
final readonly class IndexDefinition
{
    /**
     * @param  list<string>  $searchable
     * @param  list<string>  $filterable
     * @param  list<string>  $sortable
     * @param  array<string, list<string>>  $synonyms
     */
    public function __construct(
        public string $name,
        public array $searchable,
        public array $filterable = [],
        public array $sortable = [],
        public array $synonyms = [],
        public string $primaryKey = 'id',
    ) {}
}
