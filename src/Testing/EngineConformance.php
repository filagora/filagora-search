<?php

declare(strict_types=1);

namespace Filagora\Search\Testing;

use Closure;
use Filagora\Search\Contracts\SearchEngine;
use Filagora\Search\Enums\SortDirection;
use Filagora\Search\Filters\All;
use Filagora\Search\Filters\Any;
use Filagora\Search\Filters\Equals;
use Filagora\Search\Filters\In;
use Filagora\Search\Filters\Not;
use Filagora\Search\Filters\Range;
use Filagora\Search\Support\Document;
use Filagora\Search\Support\IndexDefinition;
use Filagora\Search\Support\SearchQuery;
use Filagora\Search\Support\Sort;
use PHPUnit\Framework\Assert;

/**
 * The behaviour every engine must have. An adapter's tests run each check against their engine:
 *
 *     foreach (EngineConformance::checks($engine, $settle) as $name => $check) {
 *         it($name, $check);
 *     }
 *
 * `$settle` is called after writes by an engine that applies them later (it should wait until the
 * engine has caught up). A check throws `ConformanceFailure` when the engine is wrong. The index it
 * uses is called `conformance_items`; checks empty it before they start.
 */
final class EngineConformance
{
    public const INDEX = 'conformance_items';

    public static function definition(): IndexDefinition
    {
        return new IndexDefinition(
            name: self::INDEX,
            searchable: ['title', 'body'],
            filterable: ['kind', 'tags', 'price', 'active'],
            sortable: ['price'],
        );
    }

    /**
     * @return array<string, Closure(): void>
     */
    public static function checks(SearchEngine $engine, ?Closure $settle = null): array
    {
        $settle ??= static function (): void {};
        $index = self::definition();

        $prepare = function () use ($engine, $index, $settle): void {
            $engine->syncSettings($index);
            $engine->flush($index);
            $engine->index($index, EngineConformance::documents());
            $settle();
        };

        $ids = function (SearchQuery $query) use ($engine, $index): array {
            $found = $engine->search($index, $query)->ids();
            $found = array_map('intval', $found);

            return $found;
        };

        $sorted = static function (array $ids): array {
            sort($ids);

            return $ids;
        };

        return [
            'returns every document for an empty query' => function () use ($prepare, $engine, $index, $sorted, $ids): void {
                $prepare();
                EngineConformance::same([1, 2, 3, 4, 5, 6], $sorted($ids(new SearchQuery(perPage: 50))), 'ids');
                EngineConformance::same(6, $engine->search($index, new SearchQuery(perPage: 2))->total, 'total ignores the page size');
            },
            'finds words in the searchable fields, ignoring case' => function () use ($prepare, $sorted, $ids): void {
                $prepare();
                EngineConformance::same([1, 2], $sorted($ids(new SearchQuery('PAYPAL', perPage: 50))), 'word in title or body');
                EngineConformance::same([], $ids(new SearchQuery('zzzunknown')), 'no match');
            },
            'requires every word to match' => function () use ($prepare, $ids): void {
                $prepare();
                EngineConformance::same([2], $ids(new SearchQuery('paypal checkout')), 'both words');
            },
            'ranks a match in an earlier searchable field first' => function () use ($prepare, $ids): void {
                $prepare();
                EngineConformance::same([1, 2], $ids(new SearchQuery('paypal')), 'title before body');
            },
            'filters on equality, and on a list containing the value' => function () use ($prepare, $sorted, $ids): void {
                $prepare();
                EngineConformance::same([1, 3, 5], $sorted($ids(new SearchQuery(filter: new Equals('kind', 'plugin'), perPage: 50))), 'equals');
                EngineConformance::same([2, 4], $sorted($ids(new SearchQuery(filter: new Equals('tags', 'payments'), perPage: 50))), 'list contains');
                EngineConformance::same([1, 3], $sorted($ids(new SearchQuery(filter: new Equals('active', true), perPage: 50))), 'boolean');
            },
            'filters on any of several values, and on none' => function () use ($prepare, $sorted, $ids): void {
                $prepare();
                EngineConformance::same([1, 2, 4], $sorted($ids(new SearchQuery(filter: new In('tags', ['shipping', 'payments']), perPage: 50))), 'any of');
                EngineConformance::same([], $ids(new SearchQuery(filter: new In('kind', []))), 'empty list matches nothing');
            },
            'filters on a numeric range, either bound or both' => function () use ($prepare, $sorted, $ids): void {
                $prepare();
                EngineConformance::same([2, 3, 4, 5, 6], $sorted($ids(new SearchQuery(filter: new Range('price', gte: 20), perPage: 50))), 'at least');
                EngineConformance::same([1, 2], $sorted($ids(new SearchQuery(filter: new Range('price', lte: 20), perPage: 50))), 'at most');
                EngineConformance::same([2, 3], $sorted($ids(new SearchQuery(filter: new Range('price', gte: 20, lte: 30), perPage: 50))), 'between, inclusive');
            },
            'combines filters with all, any and not' => function () use ($prepare, $sorted, $ids): void {
                $prepare();
                $plugin = new Equals('kind', 'plugin');
                $payments = new Equals('tags', 'payments');
                EngineConformance::same([], $sorted($ids(new SearchQuery(filter: new All($plugin, $payments), perPage: 50))), 'all');
                EngineConformance::same([1, 2, 3, 4, 5], $sorted($ids(new SearchQuery(filter: new Any($plugin, $payments), perPage: 50))), 'any');
                EngineConformance::same([2, 4, 6], $sorted($ids(new SearchQuery(filter: new Not($plugin), perPage: 50))), 'not');
                EngineConformance::same([1, 3], $sorted($ids(new SearchQuery(filter: new All($plugin, new Not(new Range('price', gte: 40))), perPage: 50, sort: [new Sort('price', SortDirection::ASC)]))), 'nested');
                EngineConformance::same([1, 2, 3, 4, 5, 6], $sorted($ids(new SearchQuery(filter: new All, perPage: 50))), 'no filters in all hold for everything');
                EngineConformance::same([], $ids(new SearchQuery(filter: new Any)), 'no filters in any hold for nothing');
            },
            'treats quotes, brackets and keywords in values as data' => function () use ($prepare, $engine, $index, $settle, $ids): void {
                $prepare();
                $engine->index($index, [new Document(7, ['title' => 'Odd one', 'body' => '', 'kind' => 'a" OR kind = "plugin', 'tags' => ['x] AND [y'], 'price' => 1, 'active' => false])]);
                $settle();
                EngineConformance::same([7], $ids(new SearchQuery(filter: new Equals('kind', 'a" OR kind = "plugin'))), 'quoted value');
                EngineConformance::same([7], $ids(new SearchQuery(filter: new In('tags', ['x] AND [y', 'q']))), 'bracketed value');
                EngineConformance::same([], $ids(new SearchQuery(filter: new Equals('kind', 'zzz" OR kind = "plugin'))), 'a value cannot add a condition');
            },
            'sorts by a field, ascending or descending' => function () use ($prepare, $ids): void {
                $prepare();
                EngineConformance::same([1, 2, 3, 4, 5, 6], $ids(new SearchQuery(sort: [new Sort('price', SortDirection::ASC)], perPage: 50)), 'ascending');
                EngineConformance::same([6, 5, 4, 3, 2, 1], $ids(new SearchQuery(sort: [new Sort('price', SortDirection::DESC)], perPage: 50)), 'descending');
            },
            'pages results and returns nothing past the end' => function () use ($prepare, $engine, $index, $ids): void {
                $prepare();
                $by = [new Sort('price', SortDirection::ASC)];
                EngineConformance::same([1, 2], $ids(new SearchQuery(sort: $by, page: 1, perPage: 2)), 'first page');
                EngineConformance::same([3, 4], $ids(new SearchQuery(sort: $by, page: 2, perPage: 2)), 'second page');
                EngineConformance::same([], $ids(new SearchQuery(sort: $by, page: 9, perPage: 2)), 'past the end');
                EngineConformance::same(6, $engine->search($index, new SearchQuery(sort: $by, page: 9, perPage: 2))->total, 'total past the end');
            },
            'replaces a document with the same id, removes by id and flushes' => function () use ($prepare, $engine, $index, $settle, $sorted, $ids): void {
                $prepare();
                $engine->index($index, [new Document(1, ['title' => 'Renamed', 'body' => '', 'kind' => 'plugin', 'tags' => [], 'price' => 10, 'active' => true])]);
                $settle();
                EngineConformance::same([1], $ids(new SearchQuery('renamed')), 'replaced');
                EngineConformance::same([2], $ids(new SearchQuery('paypal', perPage: 50)), 'old text gone');

                $engine->remove($index, [1, 2]);
                $settle();
                EngineConformance::same([3, 4, 5, 6], $sorted($ids(new SearchQuery(perPage: 50))), 'removed');

                $engine->flush($index);
                $settle();
                EngineConformance::same([], $ids(new SearchQuery(perPage: 50)), 'flushed');
            },
            'reports how many documents it holds, or nothing at all' => function () use ($prepare, $engine, $index): void {
                $prepare();
                $stats = $engine->stats($index);
                EngineConformance::same(true, $stats === null || $stats->documents === 6, 'document count');
            },
        ];
    }

    /**
     * @return list<Document>
     */
    public static function documents(): array
    {
        return [
            new Document(1, ['title' => 'PayPal gateway', 'body' => 'Take cards', 'kind' => 'plugin', 'tags' => ['shipping'], 'price' => 10, 'active' => true]),
            new Document(2, ['title' => 'Checkout helper', 'body' => 'Works with PayPal checkout', 'kind' => 'theme', 'tags' => ['payments', 'shipping'], 'price' => 20, 'active' => false]),
            new Document(3, ['title' => 'Template', 'body' => 'Plain', 'kind' => 'plugin', 'tags' => [], 'price' => 30, 'active' => true]),
            new Document(4, ['title' => 'Reports', 'body' => 'Sales', 'kind' => 'theme', 'tags' => ['payments'], 'price' => 40, 'active' => false]),
            new Document(5, ['title' => 'Backup', 'body' => 'Database', 'kind' => 'plugin', 'tags' => ['ops'], 'price' => 50, 'active' => false]),
            new Document(6, ['title' => 'Docs', 'body' => 'Manual', 'kind' => 'theme', 'tags' => ['ops'], 'price' => 60, 'active' => false]),
        ];
    }

    public static function same(mixed $expected, mixed $actual, string $what): void
    {
        if (class_exists(Assert::class)) {
            Assert::assertSame($expected, $actual, $what);

            return;
        }

        if ($expected !== $actual) {
            throw new ConformanceFailure(sprintf('%s: expected %s, got %s', $what, json_encode($expected), json_encode($actual)));
        }
    }
}
