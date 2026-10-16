# Changelog

All notable changes to this package are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## [Unreleased]

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
