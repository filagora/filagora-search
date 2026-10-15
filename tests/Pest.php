<?php

declare(strict_types=1);

use Filagora\Search\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

pest()->extend(TestCase::class)->use(RefreshDatabase::class)->in('Feature');
