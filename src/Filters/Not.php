<?php

declare(strict_types=1);

namespace Filagora\Search\Filters;

use Filagora\Search\Contracts\Filter;

final readonly class Not implements Filter
{
    public function __construct(public Filter $filter) {}
}
