# Changelog

All notable changes to this package are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## [Unreleased]

## [0.2.3] - 2026-11-13

### Changed

- The README follows the layout shared by every Filagora package (What it provides, Requirements, Install, Configuration, Extending, Testing, Local development, Licence), so the docs page built from it reads the same as the others. No code changes.

## [0.2.2] - 2026-11-06

### Added
- `SyncedExplicitly`: an index filled only by a run someone starts (an ingest, a backfill) is left alone by `ModelSync`, so saving one of its rows queues no sync and makes no embedding call. It can still be searched and have its settings applied.

## [0.2.1] - 2026-11-06

### Changed
- The `$progress` callback of `Indexer::reindex()` and `backfill()` is also given the last key it has looked at, so an interrupted run can be resumed from the next key.

## [0.2.0] - 2026-11-06

### Added
- **Search by meaning.** `IndexDefinition::$vectorDimensions` makes an index one whose documents carry vectors (made by the caller; this package never calls an embedding service). A `SearchQuery` with a `vector` (and, if it has words too, a `semanticRatio` between 0 and 1) finds the closest documents. Meilisearch declares a user-provided embedder only on such an index, marks which documents have a vector, opts the others out of the embedder (so a later settings update cannot fail on a document with no vector) and never returns a document without one for a vector query; a partial update leaves a stored vector alone. The array engine ranks by cosine similarity. Both report the `VECTORS` capability, and `EngineConformance::vectorChecks()` holds an engine to it. A vector of the wrong length, or a vector query on an index without vectors, is refused.
- `BatchesDocuments`: an index that makes the documents of a whole chunk at once (one embedding call per chunk instead of one per row). A full reindex uses it; a saved row still goes through `toDocument()`.

## [0.1.1] - 2026-10-28

### Added
- `MatchingStrategy` and `SearchQuery::$matching`: `All` (the default, every word must match) or `Last`, which lets Meilisearch drop words from the end of the query until something matches. Other engines treat `Last` as `All`.

## [0.1.0] - 2026-10-16

First release.

### Added
- Keeping the index fresh: every save, delete and restore of a model that belongs to a registered index queues a `SyncSearchDocument` (on the `sync` connection unless `queue` is on, after the transaction commits unless `after_commit` is off). A failing engine is logged and never breaks the save. The `database` engine does nothing.
- `Indexer`: `sync`, `forget`, `reindex` (resume with a start key, batch size, limit, pause, progress) and `backfill` of one field group.
- Commands `filagora:search:reindex`, `filagora:search:sync-settings`, `filagora:search:backfill` and `filagora:search:check-health` (non-zero exit on a problem), and a `Search` check for `filagora:doctor`.
- `SearchEngine::update()` (merge fields into existing documents), `SearchIndex::expectedCount()`, the optional `GroupedSearchIndex` (named field groups for partial updates) and `WaitsForTasks`.
- `MeilisearchEngine` over Meilisearch's HTTP API (no SDK dependency): settings sync that waits for and verifies its task, `waitForTasks()` for changes applied later, every-word matching, a capped-total flag, stats with recent failed tasks, and an index prefix.
- `FilterCompiler`: turns the filter tree into a Meilisearch filter; fields must be filterable on the index, values are quoted and escaped, and always-true or always-false conditions are folded away.
- Config for the engine (`meilisearch.host`, `key`, `prefix`, `timeout`, `max_total_hits`) and for health checks (`health.*`).
- CI and DDEV start a Meilisearch for the tests; the conformance suite passes against it.
- `SearchEngine` contract and a portable filter tree (`Equals`, `In`, `Range`, `All`, `Any`, `Not`), with `Document`, `IndexDefinition`, `SearchQuery` and `SearchResult` (engines return ids, never models).
- `ArrayEngine` (in memory, for tests) and `DatabaseEngine` (each index answers from its own tables), and `SearchEngines` to add more by name.
- `SearchIndex` contract and `SearchIndexRegistry`, so packages and hosts register what is searchable and how to answer from the database.
- `Search`, the entry point: asks the configured engine and, if it fails, logs it, opens a circuit breaker and answers from the index's database fallback until the cooldown ends.
- `Filagora\Search\Testing\EngineConformance`: the checks every engine must pass, for anyone writing an adapter.
