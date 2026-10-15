<?php

declare(strict_types=1);

use Filagora\Search\Engines\ArrayEngine;
use Filagora\Search\Filters\Equals;
use Filagora\Search\Indexing\HealthChecker;
use Filagora\Search\Indexing\Indexer;
use Filagora\Search\Jobs\SyncSearchDocument;
use Filagora\Search\Support\Document;
use Filagora\Search\Support\IndexDefinition;
use Filagora\Search\Support\IndexStats;
use Filagora\Search\Support\SearchEngines;
use Filagora\Search\Support\SearchIndexRegistry;
use Filagora\Search\Support\SearchQuery;
use Filagora\Search\Tests\Support\TestUser;
use Filagora\Search\Tests\Support\UserIndex;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;

function withArrayEngine(?ArrayEngine $engine = null): ArrayEngine
{
    $engine ??= new ArrayEngine;
    app(SearchIndexRegistry::class)->register(new UserIndex);
    app(SearchEngines::class)->extend('array', fn () => $engine);
    config(['filagora-search.engine' => 'array']);
    $engine->syncSettings((new UserIndex)->definition());

    return $engine;
}

/**
 * @return list<int>
 */
function indexed(ArrayEngine $engine, string $text = ''): array
{
    return array_map('intval', $engine->search((new UserIndex)->definition(), new SearchQuery($text, perPage: 100))->ids());
}

it('indexes a row when it is created and keeps it current when it changes', function () {
    $engine = withArrayEngine();

    $user = TestUser::make('Alice');
    expect(indexed($engine, 'alice'))->toBe([$user->id]);

    $user->update(['name' => 'Alicia']);
    expect(indexed($engine, 'alice'))->toBe([])->and(indexed($engine, 'alicia'))->toBe([$user->id]);
});

it('removes a row that is deleted, and one that stops being searchable', function () {
    $engine = withArrayEngine();
    $gone = TestUser::make('Gone');
    $hidden = TestUser::make('Hides');

    expect(indexed($engine))->toBe([$gone->id, $hidden->id]);

    $hidden->update(['email' => 'hidden@example.test']);
    expect(indexed($engine))->toBe([$gone->id]);

    $gone->delete();
    expect(indexed($engine))->toBe([]);
});

it('does not index a row that is not searchable to begin with', function () {
    $engine = withArrayEngine();

    TestUser::make('Secret', 'hidden-secret@example.test');

    expect(indexed($engine))->toBe([]);
});

it('does nothing with the database engine', function () {
    Queue::fake();
    app(SearchIndexRegistry::class)->register(new UserIndex);

    TestUser::make('Alice');

    Queue::assertNothingPushed();
    expect(app(Indexer::class)->isActive())->toBeFalse();
});

it('waits for the transaction to commit, so a rolled-back write is never indexed', function () {
    $engine = withArrayEngine();

    try {
        DB::transaction(function () {
            TestUser::make('Phantom');

            throw new RuntimeException('roll back');
        });
    } catch (RuntimeException) {
    }

    expect(indexed($engine))->toBe([]);

    DB::transaction(fn () => TestUser::make('Real'));
    expect(indexed($engine, 'real'))->toHaveCount(1);
});

it('can index inside the transaction when told not to wait', function () {
    $engine = withArrayEngine();
    config(['filagora-search.after_commit' => false]);

    DB::transaction(function () use ($engine) {
        $user = TestUser::make('Early');
        expect(indexed($engine, 'early'))->toBe([$user->id]);
    });
});

it('queues the sync when the queue is on', function () {
    withArrayEngine();
    Queue::fake();
    config(['filagora-search.queue' => true, 'filagora-search.after_commit' => false]);

    $user = TestUser::make('Queued');

    Queue::assertPushed(SyncSearchDocument::class, fn ($job) => $job->index === 'users' && $job->key === $user->id);
});

it('does not touch the index for a model no index covers', function () {
    withArrayEngine();
    Queue::fake();
    $other = new class extends Model
    {
        protected $table = 'password_reset_tokens';

        protected $guarded = [];

        public $timestamps = false;

        protected $primaryKey = 'email';

        public $incrementing = false;

        protected $keyType = 'string';
    };

    $other->newQuery()->create(['email' => 'x@example.test', 'token' => 't']);

    Queue::assertNothingPushed();
});

it('logs and carries on when the engine fails while syncing', function () {
    withArrayEngine();
    Log::spy();
    $failing = new class extends ArrayEngine
    {
        public function index(IndexDefinition $index, iterable $documents): void
        {
            throw new RuntimeException('engine down');
        }
    };
    app(SearchEngines::class)->extend('array', fn () => $failing);

    $user = TestUser::make('Resilient');

    expect($user->exists)->toBeTrue();
    Log::shouldHaveReceived('warning')->once();
});

it('ignores a sync for an index that is no longer registered', function () {
    withArrayEngine();
    $engine = app(SearchEngines::class)->engine();

    (new SyncSearchDocument('unknown', 1))->handle(app(Indexer::class), app(SearchIndexRegistry::class));

    expect($engine->stats((new UserIndex)->definition())?->documents)->toBe(0);
});

it('reindexes every searchable row in batches, reporting progress', function () {
    $engine = withArrayEngine();
    $users = collect(range(1, 5))->map(fn ($n) => TestUser::make("User{$n}"));
    TestUser::make('Hidden', 'hidden@example.test');
    $engine->flush((new UserIndex)->definition());
    $steps = [];

    $sent = app(Indexer::class)->reindex(new UserIndex, batchSize: 2, progress: function (int $sent) use (&$steps) {
        $steps[] = $sent;
    });

    expect($sent)->toBe(5)->and($steps)->toBe([2, 4, 5])->and(indexed($engine))->toBe($users->pluck('id')->all());
});

it('resumes a reindex at a key and stops at a limit', function () {
    $engine = withArrayEngine();
    $users = collect(range(1, 6))->map(fn ($n) => TestUser::make("User{$n}"));
    $engine->flush((new UserIndex)->definition());

    $sent = app(Indexer::class)->reindex(new UserIndex, fromId: $users[2]->id, batchSize: 2, limit: 3);

    expect($sent)->toBe(3)->and(indexed($engine))->toBe([$users[2]->id, $users[3]->id, $users[4]->id]);
});

it('back-fills one field group without touching the other fields', function () {
    $engine = withArrayEngine();
    $user = TestUser::make('Keeper');
    $definition = (new UserIndex)->definition();
    $engine->index($definition, [new Document($user->id, ['name' => 'Keeper', 'email' => 'stale'])]);

    $sent = app(Indexer::class)->backfill(new UserIndex, 'contact');

    expect($sent)->toBe(1)
        ->and($engine->search($definition, new SearchQuery('keeper', filter: new Equals('email', $user->email)))->ids())->toBe([$user->id])
        ->and(fn () => app(Indexer::class)->backfill(new UserIndex, 'nope'))->toThrow(InvalidArgumentException::class);
});

it('reports a short or unreachable index as a health problem', function () {
    $engine = withArrayEngine();
    config(['filagora-search.health.document_count_tolerance' => 1]);
    TestUser::make('A');
    TestUser::make('B');
    TestUser::make('C');
    $health = app(HealthChecker::class);

    expect($health->problems())->toBe([]);

    $engine->flush((new UserIndex)->definition());
    expect($health->problems())->toHaveCount(1)->and($health->problems()[0])->toContain('holds 0 documents, expected about 3')
        ->and($health->problems(['other']))->toBe([]);

    app(SearchEngines::class)->extend('array', fn () => new ArrayEngine);
    expect($health->problems()[0])->toContain('could not report its stats');
});

it('reports recent failed tasks as a health problem', function () {
    withArrayEngine();
    $failing = new class extends ArrayEngine
    {
        public function stats(IndexDefinition $index): IndexStats
        {
            return new IndexStats(0, false, 2);
        }
    };
    app(SearchEngines::class)->extend('array', fn () => $failing);

    expect(app(HealthChecker::class)->problems())->toBe(['Index `users`: 2 indexing task(s) failed in the last 24 hours.']);
});
