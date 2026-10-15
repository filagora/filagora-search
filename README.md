# Filagora Search

Search for Filagora that does not tie you to one engine. It defines a `SearchEngine` contract and
a portable filter tree, ships a Meilisearch engine and a database fallback, and keeps the index
fresh and healthy with commands. Requires [`filagora/filagora-core`](https://github.com/filagora/filagora-core).

> In progress, unreleased. The package is at `0.1`: the API may change between `0.x` minor
> versions. There is no frontend: you build the search pages.

## Install

Not yet released.

## Testing

```bash
composer test
```

Tests run against a real MariaDB database named `testing`.

## Licence

MIT. See [LICENSE](LICENSE).
