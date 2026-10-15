<?php

declare(strict_types=1);

namespace Filagora\Search\Support;

/**
 * One thing in an index: an id and flat fields (scalars, null, or lists of scalars). Related data
 * is copied in so the engine can rank and filter without a join.
 */
final readonly class Document
{
    /**
     * @param  array<string, scalar|list<scalar>|null>  $fields
     * @param  list<float>|null  $vectors  Reserved for engines that support vectors.
     */
    public function __construct(public int|string $id, public array $fields, public ?array $vectors = null) {}
}
