<?php

declare(strict_types=1);

use Filagora\Core\Doctor\CheckStatus;
use Filagora\Core\Doctor\Doctor;
use Filagora\Search\Engines\ArrayEngine;
use Filagora\Search\Engines\MeilisearchEngine;
use Filagora\Search\Support\Document;
use Filagora\Search\Support\SearchEngines;
use Filagora\Search\Support\SearchIndexRegistry;
use Filagora\Search\Support\SearchQuery;
use Filagora\Search\Tests\Support\FakeIndex;
use Filagora\Search\Tests\Support\TestUser;
use Filagora\Search\Tests\Support\UserIndex;
use Illuminate\Support\Facades\Http;

function commandEngine(): ArrayEngine
{
    $engine = new ArrayEngine;
    app(SearchIndexRegistry::class)->register(new UserIndex);
    app(SearchEngines::class)->extend('array', fn () => $engine);
    config(['filagora-search.engine' => 'array']);

    return $engine;
}

it('says there is nothing to do with the database engine', function () {
    app(SearchIndexRegistry::class)->register(new UserIndex);

    $this->artisan('filagora:search:reindex')->expectsOutput('The database engine stores nothing; there is nothing to reindex.')->assertExitCode(0);
    $this->artisan('filagora:search:sync-settings')->expectsOutput('The database engine has no settings to apply.')->assertExitCode(0);
    $this->artisan('filagora:search:backfill', ['index' => 'users', 'group' => 'contact'])->expectsOutput('The database engine stores nothing; there is nothing to backfill.')->assertExitCode(0);
    $this->artisan('filagora:search:check-health')->expectsOutput('The database engine stores nothing; nothing to check.')->assertExitCode(0);
});

it('reindexes the chosen indexes, flushing first when asked', function () {
    $engine = commandEngine();
    $one = TestUser::make('One');
    $two = TestUser::make('Two');
    $engine->flush((new UserIndex)->definition());

    $this->artisan('filagora:search:reindex', ['--index' => ['users'], '--batch-size' => 1])->expectsOutput('Index `users`: sent 2 document(s).')->assertExitCode(0);
    expect(array_map('intval', $engine->search((new UserIndex)->definition(), new SearchQuery)->ids()))->toBe([$one->id, $two->id]);

    $engine->index((new UserIndex)->definition(), [new Document(999, ['name' => 'Stale', 'email' => 'x'])]);
    $this->artisan('filagora:search:reindex', ['--flush' => true, '--sync-settings' => true, '--limit' => 1])->expectsOutput('Index `users`: sent 1 document(s).')->assertExitCode(0);
    expect(array_map('intval', $engine->search((new UserIndex)->definition(), new SearchQuery)->ids()))->toBe([$one->id]);
});

it('resumes a reindex with --from-id for one index', function () {
    $engine = commandEngine();
    $one = TestUser::make('One');
    $two = TestUser::make('Two');
    $engine->flush((new UserIndex)->definition());

    $this->artisan('filagora:search:reindex', ['--index' => ['users'], '--from-id' => $two->id])->expectsOutput('Index `users`: sent 1 document(s).')->assertExitCode(0);

    expect(array_map('intval', $engine->search((new UserIndex)->definition(), new SearchQuery)->ids()))->toBe([$two->id])->and($one->id)->not->toBe($two->id);
});

it('refuses --from-id for several indexes, and an unknown index', function () {
    commandEngine();
    app(SearchIndexRegistry::class)->register(new FakeIndex('other'));

    $this->artisan('filagora:search:reindex', ['--from-id' => 1])->expectsOutput('--from-id needs exactly one --index.')->assertExitCode(1);
    $this->artisan('filagora:search:reindex', ['--index' => ['nope']])->expectsOutputToContain('No search index is registered as [nope]. Known: users, other.')->assertExitCode(1);
    $this->artisan('filagora:search:sync-settings', ['--index' => ['nope']])->assertExitCode(1);
    $this->artisan('filagora:search:check-health', ['--index' => ['nope']])->assertExitCode(1);
});

it('applies the settings of the chosen indexes', function () {
    $engine = commandEngine();

    $this->artisan('filagora:search:sync-settings')->expectsOutput('Index `users`: settings applied.')->assertExitCode(0);

    expect($engine->stats((new UserIndex)->definition())?->documents)->toBe(0);
});

it('back-fills a group, and refuses an unknown index, group or an index without groups', function () {
    commandEngine();
    app(SearchIndexRegistry::class)->register(new FakeIndex('plain'));
    TestUser::make('One');

    $this->artisan('filagora:search:backfill', ['index' => 'users', 'group' => 'contact'])->expectsOutput('Index `users`: updated 1 document(s) with group `contact`.')->assertExitCode(0);
    $this->artisan('filagora:search:backfill', ['index' => 'users', 'group' => 'nope'])->expectsOutputToContain('Groups: contact.')->assertExitCode(1);
    $this->artisan('filagora:search:backfill', ['index' => 'plain', 'group' => 'contact'])->expectsOutput('Index [plain] has no field groups.')->assertExitCode(1);
    $this->artisan('filagora:search:backfill', ['index' => 'missing', 'group' => 'contact'])->expectsOutput('No search index is registered as [missing].')->assertExitCode(1);
});

it('checks health, failing when an index is short', function () {
    $engine = commandEngine();
    config(['filagora-search.health.document_count_tolerance' => 0]);
    TestUser::make('One');

    $this->artisan('filagora:search:check-health')->expectsOutput('All search indexes are healthy.')->assertExitCode(0);

    $engine->flush((new UserIndex)->definition());
    $this->artisan('filagora:search:check-health')->expectsOutputToContain('holds 0 documents, expected about 1')->assertExitCode(1);
});

it('adds an engine check to the doctor', function () {
    $results = collect(app(Doctor::class)->run())->keyBy('name');
    expect($results['Search']->status)->toBe(CheckStatus::Pass)->and($results['Search']->message)->toContain('database engine');

    $engine = commandEngine();
    expect(collect(app(Doctor::class)->run())->keyBy('name')['Search']->status)->toBe(CheckStatus::Fail);

    $engine->syncSettings((new UserIndex)->definition());
    expect(collect(app(Doctor::class)->run())->keyBy('name')['Search']->status)->toBe(CheckStatus::Pass);

    $engine->flush((new UserIndex)->definition());
    TestUser::make('One');
    config(['filagora-search.health.document_count_tolerance' => 0]);
    $engine->flush((new UserIndex)->definition());

    $result = collect(app(Doctor::class)->run())->keyBy('name')['Search'];
    expect($result->status)->toBe(CheckStatus::Fail)->and($result->message)->toContain('expected about 1');
});

it('reindexes into a real Meilisearch and finds the rows, then reports a healthy index', function () {
    $host = (string) (getenv('MEILISEARCH_HOST') ?: 'http://localhost:7700');

    try {
        $up = Http::timeout(2)->get($host.'/health')->successful();
    } catch (Throwable) {
        $up = false;
    }

    if (! $up) {
        getenv('CI') ? $this->fail('Meilisearch is not reachable in CI.') : $this->markTestSkipped('No Meilisearch reachable.');
    }

    $prefix = 'cmd_'.uniqid().'_';
    config(['filagora-search.engine' => 'meilisearch', 'filagora-search.meilisearch.host' => $host, 'filagora-search.meilisearch.key' => getenv('MEILISEARCH_KEY') ?: null, 'filagora-search.meilisearch.prefix' => $prefix]);
    app(SearchIndexRegistry::class)->register(new UserIndex);
    app(SearchEngines::class)->extend('meilisearch', fn () => MeilisearchEngine::fromConfig());
    config(['filagora-search.queue' => false]);
    $alice = TestUser::make('Alice');
    TestUser::make('Bob');
    TestUser::make('Hidden', 'hidden@example.test');

    $this->artisan('filagora:search:reindex', ['--flush' => true, '--sync-settings' => true])->expectsOutput('Index `users`: sent 2 document(s).')->assertExitCode(0);
    $this->artisan('filagora:search:check-health')->expectsOutput('All search indexes are healthy.')->assertExitCode(0);

    $engine = app(SearchEngines::class)->engine();
    expect(array_map('intval', $engine->search((new UserIndex)->definition(), new SearchQuery('alice'))->ids()))->toBe([$alice->id]);

    Http::withToken((string) getenv('MEILISEARCH_KEY'))->delete($host."/indexes/{$prefix}users");
});
