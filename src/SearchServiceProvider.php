<?php

declare(strict_types=1);

namespace Filagora\Search;

use Filagora\Core\Doctor\Doctor;
use Filagora\Core\Modules\Module;
use Filagora\Core\Modules\ModuleRegistry;
use Filagora\Search\Console\BackfillCommand;
use Filagora\Search\Console\CheckHealthCommand;
use Filagora\Search\Console\ReindexCommand;
use Filagora\Search\Console\SyncSettingsCommand;
use Filagora\Search\Doctor\SearchEngineCheck;
use Filagora\Search\Indexing\HealthChecker;
use Filagora\Search\Indexing\Indexer;
use Filagora\Search\Indexing\ModelSync;
use Filagora\Search\Support\CircuitBreaker;
use Filagora\Search\Support\SearchEngines;
use Filagora\Search\Support\SearchIndexRegistry;
use Illuminate\Support\Facades\Event;
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
        $this->app->singleton(Indexer::class);
        $this->app->singleton(HealthChecker::class);
        $this->app->singleton(ModelSync::class);
    }

    public function boot(): void
    {
        $this->app->make(ModuleRegistry::class)->register(
            new Module('search', 'Filagora Search', 'filagora/filagora-search', requires: ['core']),
        );

        $this->app->make(Doctor::class)->register($this->app->make(SearchEngineCheck::class));

        foreach (['saved', 'deleted', 'restored'] as $event) {
            Event::listen("eloquent.{$event}: *", [ModelSync::class, 'handle']);
        }

        if ($this->app->runningInConsole()) {
            $this->commands([ReindexCommand::class, SyncSettingsCommand::class, BackfillCommand::class, CheckHealthCommand::class]);

            $this->publishes([
                __DIR__.'/../config/filagora-search.php' => config_path('filagora-search.php'),
            ], 'filagora-search-config');
        }
    }
}
