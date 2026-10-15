<?php

declare(strict_types=1);

use Filagora\Search\Engines\MeilisearchEngine;
use Filagora\Search\Enums\SortDirection;
use Filagora\Search\Exceptions\MeilisearchTaskFailed;
use Filagora\Search\Filters\Any;
use Filagora\Search\Support\Document;
use Filagora\Search\Support\IndexDefinition;
use Filagora\Search\Support\SearchEngines;
use Filagora\Search\Support\SearchQuery;
use Filagora\Search\Support\Sort;
use Filagora\Search\Testing\EngineConformance;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;

/*
 * These run against a real Meilisearch (MEILISEARCH_HOST, MEILISEARCH_KEY): the DDEV service
 * locally, a container in CI. Locally they skip when none is reachable; in CI that is a failure.
 */

function meilisearchHost(): string
{
    return (string) (getenv('MEILISEARCH_HOST') ?: 'http://localhost:7700');
}

function meilisearchUp(): bool
{
    try {
        return Http::timeout(2)->get(meilisearchHost().'/health')->successful();
    } catch (Throwable) {
        return false;
    }
}

function meilisearch(string $prefix = '', int $maxTotalHits = 1000): MeilisearchEngine
{
    return new MeilisearchEngine(meilisearchHost(), getenv('MEILISEARCH_KEY') ?: null, $prefix, 5, $maxTotalHits);
}

beforeEach(function () {
    if (! meilisearchUp()) {
        if (getenv('CI')) {
            $this->fail('Meilisearch is not reachable in CI.');
        }

        $this->markTestSkipped('No Meilisearch reachable.');
    }
});

$engine = meilisearch();

foreach (EngineConformance::checks($engine, $engine->waitForTasks(...)) as $name => $check) {
    it("conforms: {$name}", $check);
}

it('creates the index with its settings and reports its stats', function () {
    $engine = meilisearch('stats_');
    $index = new IndexDefinition('things', ['title'], ['kind'], ['price'], ['paypal' => ['pay pal']]);

    $engine->syncSettings($index);
    $engine->index($index, [new Document(1, ['title' => 'a', 'kind' => 'x', 'price' => 1]), new Document('b-2', ['title' => 'b', 'kind' => 'y', 'price' => 2])]);
    $engine->waitForTasks();

    $stats = $engine->stats($index);
    $settings = Http::withToken((string) getenv('MEILISEARCH_KEY'))->get(meilisearchHost().'/indexes/stats_things/settings')->json();

    expect($stats?->documents)->toBe(2)->and($stats->recentFailures)->toBe(0)
        ->and($settings['searchableAttributes'])->toBe(['title'])
        ->and($settings['filterableAttributes'])->toBe(['kind'])
        ->and($settings['sortableAttributes'])->toBe(['price'])
        ->and($settings['synonyms'])->toBe(['paypal' => ['pay pal']])
        ->and($engine->search($index, new SearchQuery('pay pal'))->ids())->toBe([])
        ->and($engine->search($index, new SearchQuery('b'))->ids())->toBe(['b-2']);

    $engine->flush($index);
    $engine->waitForTasks();
});

it('puts the prefix in front of the index name and keeps prefixes apart', function () {
    $index = new IndexDefinition('pref', ['title']);
    $one = meilisearch('one_');
    $two = meilisearch('two_');

    foreach ([$one, $two] as $engine) {
        $engine->syncSettings($index);
        $engine->flush($index);
    }
    $one->index($index, [new Document(1, ['title' => 'only in one'])]);
    $one->waitForTasks();
    $two->waitForTasks();

    expect($one->search($index, new SearchQuery('only'))->ids())->toBe([1])
        ->and($two->search($index, new SearchQuery('only'))->ids())->toBe([])
        ->and($one->stats($index)?->documents)->toBe(1);
});

it('has no stats for an index that does not exist', function () {
    expect(meilisearch()->stats(new IndexDefinition('never_created_'.uniqid(), ['title'])))->toBeNull();
});

it('marks a total that reaches the counting limit as capped', function () {
    $engine = meilisearch('cap_', maxTotalHits: 3);
    $index = new IndexDefinition('capped', ['title']);
    $engine->syncSettings($index);
    $engine->flush($index);
    $engine->index($index, array_map(fn (int $id) => new Document($id, ['title' => 'same']), range(1, 6)));
    $engine->waitForTasks();

    $capped = $engine->search($index, new SearchQuery('same', perPage: 2));

    expect($capped->totalIsCapped)->toBeTrue()->and($capped->total)->toBe(3);
});

it('does not mark a total below the limit as capped', function () {
    $engine = meilisearch('cap2_');
    $index = new IndexDefinition('under', ['title']);
    $engine->syncSettings($index);
    $engine->flush($index);
    $engine->index($index, [new Document(1, ['title' => 'same'])]);
    $engine->waitForTasks();

    expect($engine->search($index, new SearchQuery('same'))->totalIsCapped)->toBeFalse();
});

it('answers a filter that can match nothing without asking the server', function () {
    Http::fake();
    $index = new IndexDefinition('t', ['title'], ['kind']);

    expect(meilisearch()->search($index, new SearchQuery('x', filter: new Any))->total)->toBe(0);
    Http::assertNothingSent();
});

it('refuses to sort by a field the index does not declare sortable', function () {
    $index = EngineConformance::definition();

    expect(fn () => meilisearch()->search($index, new SearchQuery(sort: [new Sort('title', SortDirection::ASC)])))->toThrow(InvalidArgumentException::class, 'title');
});

it('throws when Meilisearch rejects a request', function () {
    $engine = new MeilisearchEngine(meilisearchHost(), 'wrong-key');

    expect(fn () => $engine->search(EngineConformance::definition(), new SearchQuery))->toThrow(RequestException::class);
});

it('reports a task that Meilisearch accepted and then failed', function () {
    $engine = meilisearch();
    $index = new IndexDefinition('badsettings', ['title'], ['kind']);
    $engine->syncSettings($index);
    $engine->index($index, [new Document(1, ['title' => 'x', 'kind' => 'a'])]);

    Http::fake(['*/tasks/*' => Http::response(['status' => 'failed', 'error' => ['message' => 'it broke']])]);

    expect(fn () => $engine->waitForTasks())->toThrow(MeilisearchTaskFailed::class, 'it broke');
});

it('gives up waiting for a task that never finishes', function () {
    Http::fake(['*/tasks/*' => Http::response(['status' => 'processing'])]);

    expect(fn () => meilisearch()->waitForTask(7, timeoutSeconds: 0))->toThrow(MeilisearchTaskFailed::class, 'did not finish');
});

it('is available by name from the configuration', function () {
    config(['filagora-search.meilisearch.host' => 'http://example.invalid:7700', 'filagora-search.meilisearch.prefix' => 'p_']);

    expect(app(SearchEngines::class)->engine('meilisearch'))->toBeInstanceOf(MeilisearchEngine::class);
});
