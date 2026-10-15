<?php

declare(strict_types=1);

namespace Filagora\Search;

use Filagora\Core\Modules\Module;
use Filagora\Core\Modules\ModuleRegistry;
use Filagora\Search\Support\CircuitBreaker;
use Filagora\Search\Support\SearchEngines;
use Filagora\Search\Support\SearchIndexRegistry;
use Illuminate\Support\ServiceProvider;

class SearchServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/filagora-search.php', 'filagora-search');

        $this->app->singleton(SearchIndexRegistry::class);
        $this->app->singleton(SearchEngines::class, fn ($app): SearchEngines => new SearchEngines($app));
        $this->app->singleton(CircuitBreaker::class);
        $this->app->singleton(Search::class);
    }

    public function boot(): void
    {
        $this->app->make(ModuleRegistry::class)->register(
            new Module('search', 'Filagora Search', 'filagora/filagora-search', requires: ['core']),
        );

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/filagora-search.php' => config_path('filagora-search.php'),
            ], 'filagora-search-config');
        }
    }
}
