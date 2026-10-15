<?php

declare(strict_types=1);

namespace Filagora\Search\Contracts;

/**
 * An engine that applies writes after it has answered. Reindexing waits on it so a command does
 * not finish before the index has caught up.
 */
interface WaitsForTasks
{
    public function waitForTasks(int $timeoutSeconds = 60): void;
}
