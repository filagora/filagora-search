<?php

declare(strict_types=1);

namespace Filagora\Search\Support;

final readonly class IndexStats
{
    public function __construct(public int $documents, public bool $isIndexing = false, public int $recentFailures = 0) {}
}
