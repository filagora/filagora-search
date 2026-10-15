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

];
