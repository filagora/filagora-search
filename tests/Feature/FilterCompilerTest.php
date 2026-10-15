<?php

declare(strict_types=1);

use Filagora\Search\Engines\Meilisearch\FilterCompiler;
use Filagora\Search\Filters\All;
use Filagora\Search\Filters\Any;
use Filagora\Search\Filters\Equals;
use Filagora\Search\Filters\In;
use Filagora\Search\Filters\Not;
use Filagora\Search\Filters\Range;
use Filagora\Search\Support\IndexDefinition;

function compiled(mixed $filter): string|bool
{
    return (new FilterCompiler)->compile($filter, new IndexDefinition('t', ['title'], ['kind', 'tags', 'price', 'active']));
}

it('compiles each filter to a Meilisearch expression', function () {
    expect(compiled(new Equals('kind', 'plugin')))->toBe('kind = "plugin"')
        ->and(compiled(new Equals('price', 5)))->toBe('price = 5')
        ->and(compiled(new Equals('active', true)))->toBe('active = true')
        ->and(compiled(new Equals('active', false)))->toBe('active = false')
        ->and(compiled(new In('tags', ['a', 'b'])))->toBe('tags IN ["a", "b"]')
        ->and(compiled(new Range('price', gte: 2)))->toBe('(price >= 2)')
        ->and(compiled(new Range('price', lte: 9)))->toBe('(price <= 9)')
        ->and(compiled(new Range('price', gte: 2.5, lte: 9)))->toBe('(price >= 2.5 AND price <= 9)')
        ->and(compiled(new All(new Equals('kind', 'a'), new Equals('price', 1))))->toBe('(kind = "a" AND price = 1)')
        ->and(compiled(new Any(new Equals('kind', 'a'), new Equals('price', 1))))->toBe('(kind = "a" OR price = 1)')
        ->and(compiled(new Not(new Equals('kind', 'a'))))->toBe('NOT kind = "a"');
});

it('escapes quotes and backslashes in values so a value cannot add a condition', function () {
    expect(compiled(new Equals('kind', 'a" OR kind = "b')))->toBe('kind = "a\\" OR kind = \\"b"')
        ->and(compiled(new Equals('kind', 'back\\slash')))->toBe('kind = "back\\\\slash"')
        ->and(compiled(new In('tags', ['x] AND [y'])))->toBe('tags IN ["x] AND [y"]');
});

it('folds filters that are always true or always false', function () {
    expect(compiled(new All))->toBeTrue()
        ->and(compiled(new Any))->toBeFalse()
        ->and(compiled(new In('tags', [])))->toBeFalse()
        ->and(compiled(new Not(new In('tags', []))))->toBeTrue()
        ->and(compiled(new Not(new All)))->toBeFalse()
        ->and(compiled(new All(new Equals('kind', 'a'), new In('tags', []))))->toBeFalse()
        ->and(compiled(new All(new All, new Equals('kind', 'a'))))->toBe('(kind = "a")')
        ->and(compiled(new Any(new Equals('kind', 'a'), new All)))->toBeTrue()
        ->and(compiled(new Any(new In('tags', []), new Equals('kind', 'a'))))->toBe('(kind = "a")');
});

it('refuses a field that is not filterable on the index', function () {
    expect(fn () => compiled(new Equals('secret', 'x')))->toThrow(InvalidArgumentException::class, 'secret')
        ->and(fn () => compiled(new All(new Range('title', gte: 1))))->toThrow(InvalidArgumentException::class)
        ->and(fn () => compiled(new Not(new In('nope', ['a']))))->toThrow(InvalidArgumentException::class);
});
