# Filagora Search

Search for Filagora that does not tie you to one engine. It defines a `SearchEngine` contract and
a portable filter tree, ships a Meilisearch engine and a database fallback, and keeps the index
fresh and healthy with commands. Requires [`filagora/filagora-core`](https://github.com/filagora/filagora-core).

> In progress, unreleased. The package is at `0.1`: the API may change between `0.x` minor
> versions. There is no frontend: you build the search pages.

## How it fits together

- A package (or your app) registers a **`SearchIndex`**: one kind of thing, how a model becomes a
  `Document`, and a `fallback()` that answers the same query from the database.
- You search through **`Search`**: `app(Search::class)->search('threads', new SearchQuery('paypal', $filter))`.
  It returns ids; you load the rows and drop anything the index describes wrongly (an index is only
  as fresh as its last sync).
- The **engine** is `filagora-search.engine`: `database` (the default; no server), `array` (tests), or
  one you add with `SearchEngines::extend()`. If the engine throws, searches are answered from the
  database for `circuit.cooldown_seconds` and the failure is logged.
- **Filters** are engine-neutral: `new All(new Equals('forum_id', 3), new Range('reply_count', gte: 2))`.

## Keeping the index fresh

Saving, deleting or restoring a model that belongs to a registered index updates its document
(after the transaction commits). Set `FILAGORA_SEARCH_QUEUE=true` to do that on the queue. Failures
are logged and never break the save.

```bash
php artisan filagora:search:sync-settings            # create indexes and apply their settings
php artisan filagora:search:reindex --flush          # send every searchable row
php artisan filagora:search:reindex --index=threads --from-id=5000 --batch-size=200 --sleep-ms=50
php artisan filagora:search:backfill threads filters # one field group, as partial updates
php artisan filagora:search:check-health             # counts and failed tasks; non-zero on a problem
```

`filagora:doctor` also reports the engine's health. With the `database` engine all of these say
there is nothing to do.

## Using Meilisearch

```env
FILAGORA_SEARCH_ENGINE=meilisearch
MEILISEARCH_HOST=http://localhost:7700
MEILISEARCH_KEY=your-key
```

The engine speaks Meilisearch's HTTP API directly. Changes are applied by Meilisearch after it
has answered, so a failure shows up as a failed *task*; `syncSettings()` waits for its own tasks and
`MeilisearchEngine::waitForTasks()` waits for the rest. Every word of a query must match, and a
total that reaches `meilisearch.max_total_hits` (1000, Meilisearch's counting limit) is reported with
`totalIsCapped`.

## Writing an engine

Implement `Filagora\Search\Contracts\SearchEngine`, register it, and run the conformance checks in
your tests. If your engine applies writes later, pass a closure that waits until it has caught up:

```php
foreach (EngineConformance::checks($engine, fn () => $engine->waitForTasks()) as $name => $check) {
    it("conforms: {$name}", $check);
}
```

## Install

Not yet released.

## Testing

```bash
composer test
```

Tests run against a real MariaDB database named `testing`.

## Licence

MIT. See [LICENSE](LICENSE).
