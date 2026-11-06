<?php

declare(strict_types=1);

namespace Filagora\Search\Tests\Support;

use Filagora\Search\Contracts\SyncedExplicitly;

/**
 * Users, filled only by a run someone starts, never by saving a user.
 */
class ExplicitUserIndex extends UserIndex implements SyncedExplicitly {}
