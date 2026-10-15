<?php

declare(strict_types=1);

namespace Filagora\Search\Support;

final readonly class Hit
{
    public function __construct(public int|string $id, public ?float $score = null) {}
}
