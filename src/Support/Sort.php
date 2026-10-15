<?php

declare(strict_types=1);

namespace Filagora\Search\Support;

use Filagora\Search\Enums\SortDirection;

final readonly class Sort
{
    public function __construct(public string $field, public SortDirection $direction = SortDirection::DESC) {}
}
