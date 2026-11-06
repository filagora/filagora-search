<?php

declare(strict_types=1);

namespace Filagora\Search\Support;

use Filagora\Search\Contracts\Filter;
use Filagora\Search\Enums\MatchingStrategy;
use InvalidArgumentException;

/**
 * A search: words (empty means every document), an optional filter, an order (empty means by
 * relevance) and a page.
 */
final readonly class SearchQuery
{
    /**
     * @param  list<Sort>  $sort
     * @param  list<float>|null  $vector  Find documents by meaning: the query's vector, as long as the index's
     *                                    `vectorDimensions`. With no text the order is by similarity alone; with text,
     *                                    `semanticRatio` says how much weight the vector has against the words.
     */
    public function __construct(
        public string $text = '',
        public ?Filter $filter = null,
        public array $sort = [],
        public int $page = 1,
        public int $perPage = 20,
        public ?array $vector = null,
        public MatchingStrategy $matching = MatchingStrategy::All,
        public float $semanticRatio = 1.0,
    ) {
        if ($page < 1 || $perPage < 1) {
            throw new InvalidArgumentException('Page and page size start at 1.');
        }

        if ($semanticRatio < 0.0 || $semanticRatio > 1.0) {
            throw new InvalidArgumentException('The semantic ratio is between 0 and 1.');
        }
    }
}
