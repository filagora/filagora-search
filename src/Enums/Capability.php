<?php

declare(strict_types=1);

namespace Filagora\Search\Enums;

/**
 * What an engine can do beyond indexing and plain text search. A domain package asks before relying
 * on one (for example, sorting by a field).
 */
enum Capability: string
{
    case SORT = 'sort';
    case RANGE_FILTER = 'range_filter';
    case MULTI_VALUE_FILTER = 'multi_value_filter';
    case SYNONYMS = 'synonyms';
    case VECTORS = 'vectors';
}
