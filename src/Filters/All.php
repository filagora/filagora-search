<?php

declare(strict_types=1);

namespace Filagora\Search\Filters;

use Filagora\Search\Contracts\Filter;

/**
 * Every one of the filters holds. With none, it holds for every document.
 */
final readonly class All implements Filter
{
    /** @var list<Filter> */
    public array $filters;

    public function __construct(Filter ...$filters)
    {
        $this->filters = array_values($filters);
    }
}
