<?php

declare(strict_types=1);

namespace Filagora\Search\Filters;

use Filagora\Search\Contracts\Filter;

/**
 * The field equals the value; on a field holding a list, the list contains it.
 */
final readonly class Equals implements Filter
{
    public function __construct(public string $field, public int|string|bool $value) {}
}
