<?php

declare(strict_types=1);

namespace Filagora\Search\Console\Concerns;

use Filagora\Search\Contracts\SearchIndex;
use Filagora\Search\Support\SearchIndexRegistry;

trait ResolvesIndexes
{
    /**
     * The indexes named by `--index`, or all of them; null (after an error line) if one is unknown.
     *
     * @return array<string, SearchIndex>|null
     */
    private function selectedIndexes(SearchIndexRegistry $registry): ?array
    {
        $names = array_values(array_filter((array) $this->option('index'), 'is_string'));

        foreach ($names as $name) {
            if (! $registry->has($name)) {
                $this->error("No search index is registered as [{$name}]. Known: ".(implode(', ', array_keys($registry->all())) ?: 'none').'.');

                return null;
            }
        }

        return $names === [] ? $registry->all() : array_intersect_key($registry->all(), array_flip($names));
    }
}
