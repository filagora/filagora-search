<?php

declare(strict_types=1);

namespace Filagora\Search\Filters;

use Filagora\Search\Contracts\Filter;
use InvalidArgumentException;

/**
 * The number field is at least `$gte` and at most `$lte` (either bound may be left off, not both).
 * Timestamps are stored as integers for this.
 */
final readonly class Range implements Filter
{
    public function __construct(public string $field, public int|float|null $gte = null, public int|float|null $lte = null)
    {
        if ($gte === null && $lte === null) {
            throw new InvalidArgumentException('A range needs a lower or an upper bound.');
        }
    }
}
