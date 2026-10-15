<?php

declare(strict_types=1);

use Filagora\Search\Contracts\SearchEngine;
use Filagora\Search\Engines\ArrayEngine;
use Filagora\Search\Engines\DatabaseEngine;
use Filagora\Search\Enums\Capability;
use Filagora\Search\Filters\Range;
use Filagora\Search\Search;
use Filagora\Search\Support\CircuitBreaker;
use Filagora\Search\Support\Document;
use Filagora\Search\Support\Hit;
use Filagora\Search\Support\IndexDefinition;
use Filagora\Search\Support\IndexStats;
use Filagora\Search\Support\SearchEngines;
use Filagora\Search\Support\SearchIndexRegistry;
use Filagora\Search\Support\SearchQuery;
use Filagora\Search\Support\SearchResult;
use Filagora\Search\Tests\Support\FakeIndex;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Facades\Log;

/**
 * An engine that works or fails on demand and counts its searches.
 */
class FlakyEngine implements SearchEngine
{
    public int $searches = 0;

    public bool $failing = false;

    public function name(): string
    {
        return 'flaky';
    }

    public function syncSettings(IndexDefinition $index): void {}

    public function index(IndexDefinition $index, iterable $documents): void {}

    public function remove(IndexDefinition $index, iterable $ids): void {}

    public function flush(IndexDefinition $index): void {}

    public function search(IndexDefinition $index, SearchQuery $query): SearchResult
    {
        $this->searches++;

        return $this->failing ? throw new RuntimeException('engine down') : new SearchResult([new Hit(1, 1.0)], 1);
    }

    public function stats(IndexDefinition $index): ?IndexStats
    {
        return null;
    }

    public function capabilities(): array
    {
        return [Capability::SORT];
    }
}

function flakySetup(): array
{
    $index = new FakeIndex;
    app(SearchIndexRegistry::class)->register($index);
    $engine = new FlakyEngine;
    app(SearchEngines::class)->extend('flaky', fn () => $engine);
    config(['filagora-search.engine' => 'flaky']);

    return [$index, $engine];
}

it('uses the database engine unless told otherwise, and answers from the index fallback', function () {
    $index = new FakeIndex;
    app(SearchIndexRegistry::class)->register($index);

    expect(app(SearchEngines::class)->engine())->toBeInstanceOf(DatabaseEngine::class)
        ->and(app(SearchEngines::class)->names())->toBe(['database', 'array'])
        ->and(app(Search::class)->search('users', new SearchQuery('x'))->ids())->toBe([99])
        ->and($index->fallbackCalls)->toBe(1)
        ->and(app(SearchEngines::class)->engine('database')->stats($index->definition()))->toBeNull()
        ->and(app(SearchEngines::class)->engine('database')->capabilities())->toBe([]);
});

it('resolves named engines once, and refuses an unknown name', function () {
    $engines = app(SearchEngines::class);

    expect($engines->engine('array'))->toBeInstanceOf(ArrayEngine::class)
        ->and($engines->engine('array'))->toBe($engines->engine('array'))
        ->and(fn () => $engines->engine('nope'))->toThrow(InvalidArgumentException::class);

    $engines->extend('mine', fn (Container $c) => new ArrayEngine);
    expect($engines->engine('mine'))->toBeInstanceOf(ArrayEngine::class)->not->toBe($engines->engine('array'));
});

it('asks the engine when it works', function () {
    [$index, $engine] = flakySetup();

    expect(app(Search::class)->search('users', new SearchQuery('x'))->ids())->toBe([1])
        ->and($engine->searches)->toBe(1)
        ->and($index->fallbackCalls)->toBe(0);
});

it('falls back to the database when the engine fails, logging it and opening the circuit', function () {
    [$index, $engine] = flakySetup();
    $engine->failing = true;
    Log::spy();

    expect(app(Search::class)->search('users', new SearchQuery('x'))->ids())->toBe([99])
        ->and(app(CircuitBreaker::class)->isOpen())->toBeTrue()
        ->and($index->fallbackCalls)->toBe(1);

    Log::shouldHaveReceived('warning')->once();
});

it('goes straight to the database while the circuit is open, and tries the engine again after it closes', function () {
    [$index, $engine] = flakySetup();
    $engine->failing = true;
    $search = app(Search::class);

    $search->search('users', new SearchQuery('x'));
    $search->search('users', new SearchQuery('x'));
    $search->search('users', new SearchQuery('x'));

    expect($engine->searches)->toBe(1)->and($index->fallbackCalls)->toBe(3);

    $engine->failing = false;
    app(CircuitBreaker::class)->reset();

    expect($search->search('users', new SearchQuery('x'))->ids())->toBe([1])->and($engine->searches)->toBe(2);
});

it('keeps the circuit open for the configured cooldown only', function () {
    config(['filagora-search.circuit.cooldown_seconds' => 30]);
    $breaker = app(CircuitBreaker::class);

    $breaker->trip();
    expect($breaker->isOpen())->toBeTrue();

    $this->travel(31)->seconds();
    expect($breaker->isOpen())->toBeFalse();
});

it('refuses an index that was never registered', function () {
    expect(fn () => app(Search::class)->search('missing', new SearchQuery))->toThrow(InvalidArgumentException::class);
});

it('registers indexes by name, replacing one of the same name', function () {
    $registry = app(SearchIndexRegistry::class);
    $first = new FakeIndex;
    $second = new FakeIndex;

    $registry->register($first);
    $registry->register($second);
    $registry->register(new FakeIndex('other'));

    expect($registry->has('users'))->toBeTrue()->and($registry->has('nope'))->toBeFalse()
        ->and($registry->get('users'))->toBe($second)
        ->and(array_keys($registry->all()))->toBe(['users', 'other']);
});

it('refuses a range with no bound, and a page that starts before 1', function () {
    expect(fn () => new Range('price'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => new SearchQuery(page: 0))->toThrow(InvalidArgumentException::class)
        ->and(fn () => new SearchQuery(perPage: 0))->toThrow(InvalidArgumentException::class);
});

it('exposes the ids of a result in order', function () {
    expect((new SearchResult([new Hit(3), new Hit('a')], 2))->ids())->toBe([3, 'a']);
});

it('ranks by how early in the searchable fields the words match, then by id', function () {
    $engine = new ArrayEngine;
    $index = new IndexDefinition('t', ['title', 'body']);
    $engine->index($index, [
        new Document(1, ['title' => 'none', 'body' => 'red apple']),
        new Document(2, ['title' => 'red apple', 'body' => '']),
        new Document(3, ['title' => 'red', 'body' => 'apple']),
        new Document(4, ['title' => 'RED APPLE', 'body' => '']),
    ]);

    expect($engine->search($index, new SearchQuery('red apple'))->ids())->toBe([2, 4, 3, 1])
        ->and($engine->stats($index)?->documents)->toBe(4)
        ->and($engine->stats(new IndexDefinition('unknown', ['x'])))->toBeNull();
});
