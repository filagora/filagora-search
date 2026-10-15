<?php

declare(strict_types=1);

namespace Filagora\Search\Support;

/**
 * The ids of one page of matches, in order. Engines return ids, never models: the caller loads
 * the rows and drops anything the index describes wrongly. `totalIsCapped` is true when the engine
 * stopped counting, so `total` means "at least".
 */
final readonly class SearchResult
{
    /**
     * @param  list<Hit>  $hits
     */
    public function __construct(public array $hits, public int $total, public bool $totalIsCapped = false) {}

    /**
     * @return list<int|string>
     */
    public function ids(): array
    {
        return array_map(static fn (Hit $hit): int|string => $hit->id, $this->hits);
    }
}
