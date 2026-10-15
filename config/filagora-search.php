<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Engine
    |--------------------------------------------------------------------------
    |
    | Which engine stores and finds documents: `database` (no server; each index answers from
    | its own tables), `array` (in memory, for tests), or a name added with
    | `SearchEngines::extend()`.
    |
    */
    'engine' => env('FILAGORA_SEARCH_ENGINE', 'database'),

    /*
    |--------------------------------------------------------------------------
    | Keeping the index fresh
    |--------------------------------------------------------------------------
    |
    | `queue`: send changes to the engine on the queue instead of during the request.
    | `after_commit`: wait for the surrounding database transaction to commit first, so a
    | rolled-back write never reaches the index. `chunk_size`: rows per batch when reindexing.
    |
    */
    'queue' => env('FILAGORA_SEARCH_QUEUE', false),
    'after_commit' => true,
    'chunk_size' => 500,

    /*
    |--------------------------------------------------------------------------
    | Circuit breaker
    |--------------------------------------------------------------------------
    |
    | After the engine fails, searches are answered from the database for this many seconds
    | before the engine is tried again. `store` is a cache store name; null uses the default.
    |
    */
    'circuit' => [
        'cooldown_seconds' => 60,
        'store' => null,
    ],

    /*
    |--------------------------------------------------------------------------
    | Meilisearch
    |--------------------------------------------------------------------------
    |
    | Used when `engine` is `meilisearch`. `prefix` is put in front of every index name (so
    | several apps can share one server). `max_total_hits` is Meilisearch's counting limit: a
    | result total that reaches it is reported as "at least".
    |
    */
    'meilisearch' => [
        'host' => env('MEILISEARCH_HOST', 'http://localhost:7700'),
        'key' => env('MEILISEARCH_KEY'),
        'prefix' => env('FILAGORA_SEARCH_PREFIX', ''),
        'timeout' => 5,
        'max_total_hits' => 1000,
    ],

    /*
    |--------------------------------------------------------------------------
    | Health
    |--------------------------------------------------------------------------
    |
    | How far an index's document count may differ from the expected count, and how many hours
    | back a failed indexing task still counts as a problem.
    |
    */
    'health' => [
        'document_count_tolerance' => 50,
        'failed_task_lookback_hours' => 24,
    ],

];
