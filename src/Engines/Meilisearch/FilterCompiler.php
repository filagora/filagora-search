<?php

declare(strict_types=1);

namespace Filagora\Search\Engines\Meilisearch;

use Filagora\Search\Contracts\Filter;
use Filagora\Search\Filters\All;
use Filagora\Search\Filters\Any;
use Filagora\Search\Filters\Equals;
use Filagora\Search\Filters\In;
use Filagora\Search\Filters\Not;
use Filagora\Search\Filters\Range;
use Filagora\Search\Support\IndexDefinition;
use InvalidArgumentException;

/**
 * Turns a filter tree into a Meilisearch filter expression. Fields must be filterable on the
 * index (so a field name never comes from a user), values are always quoted and escaped, and
 * conditions that are always true or always false are folded away rather than sent.
 */
final class FilterCompiler
{
    /**
     * A string to send, true when the filter excludes nothing, false when it matches nothing.
     */
    public function compile(Filter $filter, IndexDefinition $index): string|bool
    {
        return match (true) {
            $filter instanceof Equals => $this->field($filter->field, $index).' = '.$this->value($filter->value),
            $filter instanceof In => $filter->values === [] ? false : $this->field($filter->field, $index).' IN ['.implode(', ', array_map($this->value(...), $filter->values)).']',
            $filter instanceof Range => $this->range($filter, $index),
            $filter instanceof All => $this->all($filter, $index),
            $filter instanceof Any => $this->any($filter, $index),
            $filter instanceof Not => $this->not($filter, $index),
            default => throw new InvalidArgumentException('Unsupported filter '.$filter::class.'.'),
        };
    }

    private function range(Range $range, IndexDefinition $index): string
    {
        $field = $this->field($range->field, $index);
        $parts = [];

        if ($range->gte !== null) {
            $parts[] = $field.' >= '.$range->gte;
        }

        if ($range->lte !== null) {
            $parts[] = $field.' <= '.$range->lte;
        }

        return '('.implode(' AND ', $parts).')';
    }

    private function all(All $all, IndexDefinition $index): string|bool
    {
        $parts = [];

        foreach ($all->filters as $filter) {
            $compiled = $this->compile($filter, $index);

            if ($compiled === false) {
                return false;
            }

            if ($compiled !== true) {
                $parts[] = $compiled;
            }
        }

        return $parts === [] ? true : '('.implode(' AND ', $parts).')';
    }

    private function any(Any $any, IndexDefinition $index): string|bool
    {
        $parts = [];

        foreach ($any->filters as $filter) {
            $compiled = $this->compile($filter, $index);

            if ($compiled === true) {
                return true;
            }

            if ($compiled !== false) {
                $parts[] = $compiled;
            }
        }

        return $parts === [] ? false : '('.implode(' OR ', $parts).')';
    }

    private function not(Not $not, IndexDefinition $index): string|bool
    {
        $compiled = $this->compile($not->filter, $index);

        return is_bool($compiled) ? ! $compiled : 'NOT '.$compiled;
    }

    private function field(string $field, IndexDefinition $index): string
    {
        if (! in_array($field, $index->filterable, true)) {
            throw new InvalidArgumentException("[{$field}] is not a filterable field of the [{$index->name}] index.");
        }

        return $field;
    }

    private function value(int|string|bool $value): string
    {
        return match (true) {
            is_bool($value) => $value ? 'true' : 'false',
            is_int($value) => (string) $value,
            default => '"'.str_replace(['\\', '"'], ['\\\\', '\\"'], $value).'"',
        };
    }
}
