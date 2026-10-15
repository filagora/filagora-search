<?php

declare(strict_types=1);

namespace Filagora\Search\Support;

use Closure;
use Filagora\Search\Contracts\SearchEngine;
use Filagora\Search\Engines\ArrayEngine;
use Filagora\Search\Engines\DatabaseEngine;
use Filagora\Search\Engines\MeilisearchEngine;
use Illuminate\Contracts\Container\Container;
use InvalidArgumentException;

/**
 * The engines available by name. `database` and `array` are built in; a package or host adds
 * another with `extend()`.
 */
final class SearchEngines
{
    /** @var array<string, Closure(Container): SearchEngine> */
    private array $factories = [];

    /** @var array<string, SearchEngine> */
    private array $resolved = [];

    public function __construct(private readonly Container $container)
    {
        $this->factories['database'] = static fn (Container $c): SearchEngine => new DatabaseEngine($c->make(SearchIndexRegistry::class));
        $this->factories['array'] = static fn (): SearchEngine => new ArrayEngine;
        $this->factories['meilisearch'] = static fn (): SearchEngine => MeilisearchEngine::fromConfig();
    }

    /**
     * @param  Closure(Container): SearchEngine  $factory
     */
    public function extend(string $name, Closure $factory): void
    {
        $this->factories[$name] = $factory;
        unset($this->resolved[$name]);
    }

    public function engine(?string $name = null): SearchEngine
    {
        $name ??= (string) config('filagora-search.engine', 'database');

        if (! isset($this->factories[$name])) {
            throw new InvalidArgumentException("No search engine is registered as [{$name}].");
        }

        return $this->resolved[$name] ??= ($this->factories[$name])($this->container);
    }

    /**
     * @return list<string>
     */
    public function names(): array
    {
        return array_keys($this->factories);
    }
}
