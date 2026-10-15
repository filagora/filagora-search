<?php

declare(strict_types=1);

namespace Filagora\Search\Tests\Support;

use Filagora\Search\Contracts\GroupedSearchIndex;
use Filagora\Search\Support\Document;
use Filagora\Search\Support\Hit;
use Filagora\Search\Support\IndexDefinition;
use Filagora\Search\Support\SearchQuery;
use Filagora\Search\Support\SearchResult;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Users, searchable by name, except those whose email starts with "hidden".
 */
class UserIndex implements GroupedSearchIndex
{
    public function definition(): IndexDefinition
    {
        return new IndexDefinition('users', ['name'], ['email']);
    }

    public function model(): string
    {
        return TestUser::class;
    }

    public function shouldBeSearchable(Model $model): bool
    {
        return ! str_starts_with((string) $model->getAttribute('email'), 'hidden');
    }

    public function toDocument(Model $model): Document
    {
        return new Document($model->getKey(), ['name' => $model->getAttribute('name'), 'email' => $model->getAttribute('email')]);
    }

    public function query(): Builder
    {
        return TestUser::query();
    }

    public function expectedCount(): int
    {
        return TestUser::query()->where('email', 'not like', 'hidden%')->count();
    }

    public function fallback(SearchQuery $query): SearchResult
    {
        return new SearchResult(TestUser::query()->where('name', 'like', "%{$query->text}%")->pluck('id')->map(fn ($id) => new Hit($id))->all(), 0);
    }

    public function groups(): array
    {
        return ['contact'];
    }

    public function toPartialDocument(Model $model, string $group): Document
    {
        return new Document($model->getKey(), ['email' => $model->getAttribute('email')]);
    }
}
