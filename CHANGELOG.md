# Changelog

All notable changes to this package are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## [Unreleased]

### Added
- `SearchEngine` contract and a portable filter tree (`Equals`, `In`, `Range`, `All`, `Any`, `Not`), with `Document`, `IndexDefinition`, `SearchQuery` and `SearchResult` (engines return ids, never models).
- `ArrayEngine` (in memory, for tests) and `DatabaseEngine` (each index answers from its own tables), and `SearchEngines` to add more by name.
- `SearchIndex` contract and `SearchIndexRegistry`, so packages and hosts register what is searchable and how to answer from the database.
- `Search`, the entry point: asks the configured engine and, if it fails, logs it, opens a circuit breaker and answers from the index's database fallback until the cooldown ends.
- `Filagora\Search\Testing\EngineConformance`: the checks every engine must pass, for anyone writing an adapter.
