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

    public const VECTOR_INDEX = 'conformance_vectors';

    /**
     * An index of three-dimensional vectors, for engines that can search by meaning.
     */
    public static function vectorDefinition(): IndexDefinition
    {
        return new IndexDefinition(
            name: self::VECTOR_INDEX,
            searchable: ['title'],
            filterable: ['kind'],
            vectorDimensions: 3,
        );
    }

    /**
     * What an engine with the VECTORS capability must also do. Run these in addition to `checks()`,
     * and only for such an engine.
     *
     * @return array<string, Closure(): void>
     */
    public static function vectorChecks(SearchEngine $engine, ?Closure $settle = null): array
    {
        $settle ??= static function (): void {};
        $index = self::vectorDefinition();

        $prepare = function () use ($engine, $index, $settle): void {
            $engine->syncSettings($index);
            $engine->flush($index);
            $engine->index($index, [
                new Document(1, ['title' => 'north', 'kind' => 'a'], [1.0, 0.0, 0.0]),
                new Document(2, ['title' => 'north east', 'kind' => 'b'], [0.9, 0.4, 0.0]),
                new Document(3, ['title' => 'east', 'kind' => 'a'], [0.0, 1.0, 0.0]),
                new Document(4, ['title' => 'no vector', 'kind' => 'a']),
            ]);
            $settle();
        };

        $ids = static fn (SearchQuery $query): array => array_map('intval', $engine->search($index, $query)->ids());

        return [
            'ranks documents by closeness to the query vector' => function () use ($prepare, $ids): void {
                $prepare();
                $found = $ids(new SearchQuery(vector: [1.0, 0.0, 0.0], perPage: 3));

                EngineConformance::same([1, 2, 3], $found, 'ids in order of similarity');
                EngineConformance::same([3, 2, 1], $ids(new SearchQuery(vector: [0.0, 1.0, 0.0], perPage: 3)), 'the other way round');
            },
            'never returns a document that has no vector for a vector query' => function () use ($prepare, $ids): void {
                $prepare();

                EngineConformance::same(false, in_array(4, $ids(new SearchQuery(vector: [1.0, 0.0, 0.0], perPage: 10)), true), 'document without a vector');
            },
            'narrows a vector query with a filter' => function () use ($prepare, $ids): void {
                $prepare();

                EngineConformance::same([1, 3], $ids(new SearchQuery(filter: new Equals('kind', 'a'), vector: [1.0, 0.0, 0.0], perPage: 10)), 'only kind a');
            },
            'keeps a stored vector when a partial update leaves it out' => function () use ($prepare, $engine, $index, $settle, $ids): void {
                $prepare();
                $engine->update($index, [new Document(1, ['kind' => 'b'])]);
                $settle();

                EngineConformance::same([1, 2], $ids(new SearchQuery(filter: new Equals('kind', 'b'), vector: [1.0, 0.0, 0.0], perPage: 10)), 'the field changed and the vector stayed');
            },
            'refuses a vector of the wrong length, and a vector query on an index without vectors' => function () use ($prepare, $engine, $index): void {
                $prepare();

                EngineConformance::throws(fn () => $engine->search($index, new SearchQuery(vector: [1.0, 0.0])), 'a vector of the wrong length');
                EngineConformance::throws(fn () => $engine->search(EngineConformance::definition(), new SearchQuery(vector: [1.0, 0.0, 0.0])), 'an index with no vectors');
            },
        ];
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
            'merges partial updates into existing documents and creates missing ones' => function () use ($prepare, $engine, $index, $settle, $sorted, $ids): void {
                $prepare();
                $engine->update($index, [
                    new Document(1, ['price' => 99]),
                    new Document(8, ['title' => 'Brand new', 'price' => 5]),
                ]);
                $settle();
                EngineConformance::same([1], $ids(new SearchQuery('gateway', filter: new Range('price', gte: 90))), 'merged field changed, others kept');
                EngineConformance::same([8], $ids(new SearchQuery('brand')), 'created');
                EngineConformance::same([1, 2, 3, 4, 5, 6, 8], $sorted($ids(new SearchQuery(perPage: 50))), 'nothing else changed');
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

    /**
     * @param  Closure(): mixed  $call
     */
    public static function throws(Closure $call, string $what): void
    {
        try {
            $call();
        } catch (\InvalidArgumentException) {
            return;
        }

        throw new ConformanceFailure("{$what}: expected the engine to refuse it, but it answered.");
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
