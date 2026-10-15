<?php

declare(strict_types=1);

namespace Filagora\Search\Testing;

use RuntimeException;

/**
 * An engine did not behave as the contract requires.
 */
final class ConformanceFailure extends RuntimeException {}
