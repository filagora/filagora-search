<?php

declare(strict_types=1);

namespace Filagora\Search\Engines;

use Filagora\Search\Contracts\Filter;
use Filagora\Search\Contracts\SearchEngine;
use Filagora\Search\Enums\Capability;
use Filagora\Search\Enums\SortDirection;
use Filagora\Search\Filters\All;
use Filagora\Search\Filters\Any;
use Filagora\Search\Filters\Equals;
use Filagora\Search\Filters\In;
use Filagora\Search\Filters\Not;
use Filagora\Search\Filters\Range;
use Filagora\Search\Support\Document;
use Filagora\Search\Support\Hit;
use Filagora\Search\Support\IndexDefinition;
use Filagora\Search\Support\IndexStats;
use Filagora\Search\Support\SearchQuery;
use Filagora\Search\Support\SearchResult;
use InvalidArgumentException;

/**
 * Keeps documents in memory and evaluates filters in PHP. For tests, and the reference the other
 * engines are held to. Text matching is every word as a case-insensitive substring of some
 * searchable field, ranked by the earliest field it appears in.
 */
class ArrayEngine implements SearchEngine
{
    /** @var array<string, array<string, Document>> */
    private array $documents = [];

    public function name(): string
    {
        return 'array';
    }

    public function syncSettings(IndexDefinition $index): void
    {
        $this->documents[$index->name] ??= [];
    }

    public function index(IndexDefinition $index, iterable $documents): void
    {
        foreach ($documents as $document) {
            $this->documents[$index->name][(string) $document->id] = $document;
        }
    }

    public function update(IndexDefinition $index, iterable $documents): void
    {
        foreach ($documents as $document) {
            $key = (string) $document->id;
            $existing = $this->documents[$index->name][$key] ?? null;

            $this->documents[$index->name][$key] = new Document($document->id, [...($existing->fields ?? []), ...$document->fields], $document->vectors ?? $existing?->vectors);
        }
    }

    public function remove(IndexDefinition $index, iterable $ids): void
    {
        foreach ($ids as $id) {
            unset($this->documents[$index->name][(string) $id]);
        }
    }

    public function flush(IndexDefinition $index): void
    {
        $this->documents[$index->name] = [];
    }

    public function search(IndexDefinition $index, SearchQuery $query): SearchResult
    {
        $words = preg_split('/\s+/', mb_strtolower(trim($query->text)), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $scored = [];

        if ($query->vector !== null) {
            $this->assertVectorSearchable($index, $query->vector);
        }

        foreach ($this->documents[$index->name] ?? [] as $document) {
            if ($query->filter !== null && ! $this->matches($query->filter, $document)) {
                continue;
            }

            $score = $query->vector !== null
                ? $this->vectorScore($index, $document, $words, $query)
                : $this->score($index, $document, $words);

            if ($score !== null) {
                $scored[] = [$document, $score];
            }
        }

        usort($scored, fn (array $a, array $b): int => $this->compare($a, $b, $query));

        $page = array_slice($scored, ($query->page - 1) * $query->perPage, $query->perPage);

        return new SearchResult(
            array_map(static fn (array $row): Hit => new Hit($row[0]->id, $row[1]), $page),
            count($scored),
        );
    }

    public function stats(IndexDefinition $index): ?IndexStats
    {
        return isset($this->documents[$index->name]) ? new IndexStats(count($this->documents[$index->name])) : null;
    }

    public function capabilities(): array
    {
        return [Capability::SORT, Capability::RANGE_FILTER, Capability::MULTI_VALUE_FILTER, Capability::VECTORS];
    }

    /**
     * By similarity of meaning: cosine similarity to the query vector, for the documents that have a
     * vector. Words, when given with a ratio below one, still have to match, as in a keyword search;
     * with a ratio of one they are ignored, as Meilisearch ignores them.
     *
     * @param  list<string>  $words
     */
    private function vectorScore(IndexDefinition $index, Document $document, array $words, SearchQuery $query): ?float
    {
        if ($document->vectors === null || $query->vector === null) {
            return null;
        }

        if ($words !== [] && $query->semanticRatio < 1.0 && $this->score($index, $document, $words) === null) {
            return null;
        }

        $dot = 0.0;
        $left = 0.0;
        $right = 0.0;

        foreach ($query->vector as $position => $value) {
            $other = (float) ($document->vectors[$position] ?? 0.0);
            $dot += $value * $other;
            $left += $value * $value;
            $right += $other * $other;
        }

        return $left > 0.0 && $right > 0.0 ? $dot / (sqrt($left) * sqrt($right)) : 0.0;
    }

    /**
     * @param  list<float>  $vector
     */
    private function assertVectorSearchable(IndexDefinition $index, array $vector): void
    {
        if ($index->vectorDimensions === null) {
            throw new InvalidArgumentException("The [{$index->name}] index has no vectors, so it cannot be searched by meaning.");
        }

        if (count($vector) !== $index->vectorDimensions) {
            throw new InvalidArgumentException('The query vector has '.count($vector)." dimensions; the [{$index->name}] index has {$index->vectorDimensions}.");
        }
    }

    /**
     * @param  list<string>  $words
     */
    private function score(IndexDefinition $index, Document $document, array $words): ?float
    {
        if ($words === []) {
            return 0.0;
        }

        $fields = [];

        foreach ($index->searchable as $field) {
            $value = $document->fields[$field] ?? null;
            $fields[$field] = mb_strtolower(is_array($value) ? implode(' ', array_map('strval', $value)) : (string) $value);
        }

        $score = 0.0;
        $count = count($index->searchable);

        foreach ($words as $word) {
            $weight = null;

            foreach ($index->searchable as $position => $field) {
                if (str_contains($fields[$field], $word)) {
                    $weight = (float) ($count - $position);
                    break;
                }
            }

            if ($weight === null) {
                return null;
            }

            $score += $weight;
        }

        return $score;
    }

    /**
     * @param  array{0: Document, 1: float}  $a
     * @param  array{0: Document, 1: float}  $b
     */
    private function compare(array $a, array $b, SearchQuery $query): int
    {
        foreach ($query->sort as $sort) {
            $left = $a[0]->fields[$sort->field] ?? null;
            $right = $b[0]->fields[$sort->field] ?? null;

            if ($left === $right) {
                continue;
            }

            if ($left === null) {
                return 1;
            }

            if ($right === null) {
                return -1;
            }

            $order = $left <=> $right;

            return $sort->direction === SortDirection::DESC ? -$order : $order;
        }

        if ($query->sort === [] && $a[1] !== $b[1]) {
            return $b[1] <=> $a[1];
        }

        return strcmp((string) $a[0]->id, (string) $b[0]->id);
    }

    private function matches(Filter $filter, Document $document): bool
    {
        return match (true) {
            $filter instanceof Equals => $this->holds($document, $filter->field, [$filter->value]),
            $filter instanceof In => $this->holds($document, $filter->field, $filter->values),
            $filter instanceof Range => $this->inRange($document, $filter),
            $filter instanceof All => array_all($filter->filters, fn (Filter $inner): bool => $this->matches($inner, $document)),
            $filter instanceof Any => array_any($filter->filters, fn (Filter $inner): bool => $this->matches($inner, $document)),
            $filter instanceof Not => ! $this->matches($filter->filter, $document),
            default => throw new InvalidArgumentException('Unsupported filter '.$filter::class.'.'),
        };
    }

    /**
     * @param  list<int|string|bool>  $wanted
     */
    private function holds(Document $document, string $field, array $wanted): bool
    {
        $value = $document->fields[$field] ?? null;
        $values = is_array($value) ? $value : [$value];
        $wanted = array_map($this->normalise(...), $wanted);

        return array_any($values, fn (mixed $each): bool => $each !== null && in_array($this->normalise($each), $wanted, true));
    }

    private function inRange(Document $document, Range $range): bool
    {
        $value = $document->fields[$range->field] ?? null;

        return array_any(is_array($value) ? $value : [$value], fn (mixed $each): bool => is_numeric($each)
            && ($range->gte === null || $each >= $range->gte)
            && ($range->lte === null || $each <= $range->lte));
    }

    private function normalise(mixed $value): string
    {
        return is_bool($value) ? ($value ? '1' : '0') : (string) $value;
    }
}
