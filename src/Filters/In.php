<?php

declare(strict_types=1);

namespace Filagora\Search\Filters;

use Filagora\Search\Contracts\Filter;

/**
 * The field equals any of the values; on a field holding a list, the list contains any of them.
 * No values matches nothing.
 */
final readonly class In implements Filter
{
    /**
     * @param  list<int|string|bool>  $values
     */
    public function __construct(public string $field, public array $values) {}
}
