<?php

declare(strict_types=1);

use Filagora\Search\Engines\ArrayEngine;
use Filagora\Search\Testing\EngineConformance;

foreach (EngineConformance::checks(new ArrayEngine) as $name => $check) {
    it("conforms: {$name}", $check);
}
