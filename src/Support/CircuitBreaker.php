<?php

declare(strict_types=1);

namespace Filagora\Search\Support;

use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;

/**
 * Remembers that the engine failed, so the next searches go straight to the database instead of
 * each waiting on a dead server. Held in the cache for `circuit.cooldown_seconds`.
 */
class CircuitBreaker
{
    private const KEY = 'filagora-search:circuit-open';

    public function isOpen(): bool
    {
        return (bool) $this->cache()->get(self::KEY, false);
    }

    public function trip(): void
    {
        $this->cache()->put(self::KEY, true, max(1, (int) config('filagora-search.circuit.cooldown_seconds', 60)));
    }

    public function reset(): void
    {
        $this->cache()->forget(self::KEY);
    }

    private function cache(): Repository
    {
        $store = config('filagora-search.circuit.store');

        return Cache::store(is_string($store) && $store !== '' ? $store : null);
    }
}
