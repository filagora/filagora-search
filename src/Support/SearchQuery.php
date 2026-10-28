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
     * @param  list<float>|null  $vector  Reserved for engines that support vectors.
     */
    public function __construct(
        public string $text = '',
        public ?Filter $filter = null,
        public array $sort = [],
        public int $page = 1,
        public int $perPage = 20,
        public ?array $vector = null,
        public MatchingStrategy $matching = MatchingStrategy::All,
    ) {
        if ($page < 1 || $perPage < 1) {
            throw new InvalidArgumentException('Page and page size start at 1.');
        }
    }
}
