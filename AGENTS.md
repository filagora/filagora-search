# AGENTS

Guidance for AI coding agents working in `filagora-search`. `CLAUDE.md` imports this file; edit here.

## What this is

**filagora/filagora-search**: Engine-agnostic search: a `SearchEngine` contract, Meilisearch, database and array engines, a filter tree, indexing jobs, a conformance suite, and health/sync commands.

Part of Filagora, a family of MIT-licensed Laravel 13 / Filament 5 packages (forums, CMS, entitlements, logs, compliance, messages, search, push, memberships). Composer vendor `filagora`, namespace `Filagora\<Name>\`, default table prefix `filagora_`, commands `filagora:*`, env `FILAGORA_*`. Every package is pre-1.0 (`0.x`): the API may change between minor versions.

Dependencies are declared in `composer.json` (`require` is hard, `suggest` is optional). Other packages must keep working when an optional one is absent; each package's `tests/NoEntitlements`/`Switches` directory proves that.

## Layout

`src/` (service provider, `FilagoraSearchPlugin` where there are screens, models, services, contracts, events, Filament resources), `config/filagora-search.php`, `database/migrations` + `database/schema.php`, `resources/`, `tests/` (Pest, Testbench: `testbench.yaml`).

## This package

- Other packages register indexes with this one; this package knows nothing about forums or pages. Keep it that way.
- A new engine must pass the shipped conformance suite (`Testing`); that is the definition of correct behaviour.
- Meilisearch tests need a server: CI starts one on port 7700 with key `testing-master-key` (exported as `MEILISEARCH_HOST` and `MEILISEARCH_KEY`); locally DDEV provides it where configured. Index setting changes need `filagora:search:sync-settings`.
- `filagora:search:check-health`, `filagora:search:sync-settings`, `filagora:search:reindex`, `filagora:search:backfill`.

## Commands

Run everything through DDEV from this directory (`ddev start` once; the `testing` database is created on the first `ddev restart`).

```bash
ddev composer install
ddev composer test      # Pest, on a real MariaDB
ddev composer lint      # Pint
ddev composer analyse   # Larastan (level 6)
```

Tests always run against MariaDB (database `testing`, forced in `phpunit.xml.dist`), never SQLite, so FULLTEXT, `ONLY_FULL_GROUP_BY` and collation behave like production. Do not weaken that to make a test pass. If a port is taken, `ddev poweroff` before starting another project's DDEV.

## Conventions

- PHP 8.4, `declare(strict_types=1)` in every source file (`tests/ArchTest.php` enforces it, plus no debug calls and no `env()` outside `config/`).
- Table names always go through core's `Tables::name('x')` (prefix-aware); user foreign keys through `UserKey`/`UserModel`. Never hard-code `filagora_` or `users`.
- Config holds no closures and is merged with the host's copy, so list only what differs. Env vars are `FILAGORA_`-prefixed.
- Authorization is one question, "may this user do this?", answered by core's `AccessChecker` from the package's `gates` config. Do not call a roles or permissions package directly.
- Extend through documented contracts, events and registries. Anything undocumented is `@internal`; public API follows SemVer.
- Migrations are mirrored by a snapshot in `database/schema.php` (`filagora:schema:dump`). A table change means updating the migration, the snapshot and the adoption check together, because `filagora:adopt` compares live tables against the snapshot.

## Definition of done

- Every behaviour change ships with tests; every bug fix with a regression test that fails before the fix.
- README and `CHANGELOG.md` (`[Unreleased]`) are updated in the same change.
- `composer test`, `composer lint` and `composer analyse` pass.
- Work on a branch and open a pull request (`gh pr merge --squash --delete-branch`). `master` is protected and squash-only.
- Confirm with the user before tagging a release, creating or deleting repositories or branches, or anything else outward-facing. Tags create the GitHub release from the matching `CHANGELOG.md` section; the user submits new packages to Packagist.

## Public repository: keep private details out

This repository is public and CI runs a leak scan (private patterns, any IPv4 literal, any email address not on a reserved test domain, gitleaks). Never write real hostnames, IP addresses, personal emails, the name of the site the packages were extracted from, or plan/infrastructure details into code, docs, fixtures, commit messages or PRs. Use `example.test`-style addresses.

## CI

`.github/workflows/ci.yml` calls the reusable workflows in `filagora/filagora-shared` at `@master` (tests, lint, leak scan, release). Fix CI problems there, not by copying logic into this repo.
