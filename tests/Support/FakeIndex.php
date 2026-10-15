<?php

declare(strict_types=1);

namespace Filagora\Search\Tests\Support;

use Filagora\Search\Contracts\SearchIndex;
use Filagora\Search\Support\Document;
use Filagora\Search\Support\Hit;
use Filagora\Search\Support\IndexDefinition;
use Filagora\Search\Support\SearchQuery;
use Filagora\Search\Support\SearchResult;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * An index over the test users table whose fallback returns fixed ids and counts how often it ran.
 */
class FakeIndex implements SearchIndex
{
    public int $fallbackCalls = 0;

    public function __construct(private readonly string $name = 'users') {}

    public function definition(): IndexDefinition
    {
        return new IndexDefinition($this->name, ['name']);
    }

    public function model(): string
    {
        return TestUser::class;
    }

    public function shouldBeSearchable(Model $model): bool
    {
        return true;
    }

    public function toDocument(Model $model): Document
    {
        return new Document($model->getKey(), ['name' => $model->getAttribute('name')]);
    }

    public function query(): Builder
    {
        return TestUser::query();
    }

    public function fallback(SearchQuery $query): SearchResult
    {
        $this->fallbackCalls++;

        return new SearchResult([new Hit(99)], 1);
    }
}
