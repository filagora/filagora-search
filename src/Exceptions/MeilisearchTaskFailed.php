<?php

declare(strict_types=1);

namespace Filagora\Search\Exceptions;

use RuntimeException;

/**
 * Meilisearch accepted a change and then failed to apply it. These are reported only when
 * something waits for the task, because Meilisearch answers a write before it has done it.
 */
final class MeilisearchTaskFailed extends RuntimeException {}
