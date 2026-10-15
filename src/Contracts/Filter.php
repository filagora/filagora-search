<?php

declare(strict_types=1);

namespace Filagora\Search\Contracts;

/**
 * A condition on documents, engine-neutral. Build one from the classes in `Filagora\Search\Filters`;
 * each engine compiles the tree into its own query language.
 */
interface Filter {}
