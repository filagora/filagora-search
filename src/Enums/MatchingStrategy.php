<?php

declare(strict_types=1);

namespace Filagora\Search\Enums;

/**
 * How many of a query's words a document must contain. `All` is the contract every engine meets.
 * `Last` is looser: Meilisearch drops words from the end of the query until something matches, so
 * "zen cart 1.5.8" still finds pages about Zen Cart. Engines that cannot do this treat it as `All`.
 */
enum MatchingStrategy: string
{
    case All = 'all';
    case Last = 'last';
}
