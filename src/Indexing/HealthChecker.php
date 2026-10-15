<?php

declare(strict_types=1);

namespace Filagora\Search\Indexing;

use Filagora\Search\Support\SearchEngines;
use Filagora\Search\Support\SearchIndexRegistry;

/**
 * Compares each index with what it should hold and looks for recent failed indexing tasks. Shared
 * by `filagora:search:check-health` and the doctor check.
 */
final class HealthChecker
{
    public function __construct(private readonly SearchEngines $engines, private readonly SearchIndexRegistry $indexes, private readonly Indexer $indexer) {}

    /**
     * @param  list<string>  $only  Index names; empty means all.
     * @return list<string> One line per problem; empty when healthy.
     */
    public function problems(array $only = []): array
    {
        if (! $this->indexer->isActive()) {
            return [];
        }

        $tolerance = (int) config('filagora-search.health.document_count_tolerance', 50);
        $problems = [];

        foreach ($this->indexes->all() as $name => $index) {
            if ($only !== [] && ! in_array($name, $only, true)) {
                continue;
            }

            $stats = $this->engines->engine()->stats($index->definition());

            if ($stats === null) {
                $problems[] = "Index `{$name}`: the engine could not report its stats.";

                continue;
            }

            $expected = $index->expectedCount();

            if (abs($expected - $stats->documents) > $tolerance) {
                $problems[] = "Index `{$name}`: holds {$stats->documents} documents, expected about {$expected} (tolerance {$tolerance}).";
            }

            if ($stats->recentFailures > 0) {
                $problems[] = "Index `{$name}`: {$stats->recentFailures} indexing task(s) failed in the last ".(int) config('filagora-search.health.failed_task_lookback_hours', 24).' hours.';
            }
        }

        return $problems;
    }
}
