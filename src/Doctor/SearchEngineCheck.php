<?php

declare(strict_types=1);

namespace Filagora\Search\Doctor;

use Filagora\Core\Contracts\DoctorCheck;
use Filagora\Core\Doctor\CheckResult;
use Filagora\Search\Indexing\HealthChecker;
use Filagora\Search\Indexing\Indexer;

/**
 * Whether the configured search engine is reachable and its indexes look complete.
 */
final class SearchEngineCheck implements DoctorCheck
{
    public function __construct(private readonly Indexer $indexer, private readonly HealthChecker $health) {}

    public function run(): CheckResult
    {
        if (! $this->indexer->isActive()) {
            return CheckResult::pass('Search', 'Using the database engine (no search server).');
        }

        $problems = $this->health->problems();

        return $problems === []
            ? CheckResult::pass('Search', 'Engine `'.$this->indexer->engine()->name().'` is healthy.')
            : CheckResult::fail('Search', implode(' ', $problems));
    }
}
