<?php

declare(strict_types=1);

namespace Filagora\Search\Support;

use Filagora\Search\Contracts\SearchIndex;
use InvalidArgumentException;

/**
 * The indexes packages and the host have registered, by name.
 */
final class SearchIndexRegistry
{
    /** @var array<string, SearchIndex> */
    private array $indexes = [];

    public function register(SearchIndex $index): void
    {
        $this->indexes[$index->definition()->name] = $index;
    }

    public function has(string $name): bool
    {
        return isset($this->indexes[$name]);
    }

    public function get(string $name): SearchIndex
    {
        return $this->indexes[$name] ?? throw new InvalidArgumentException("No search index is registered as [{$name}].");
    }

    /**
     * @return array<string, SearchIndex>
     */
    public function all(): array
    {
        return $this->indexes;
    }
}
