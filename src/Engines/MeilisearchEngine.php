<?php

declare(strict_types=1);

namespace Filagora\Search\Engines;

use Filagora\Search\Contracts\SearchEngine;
use Filagora\Search\Contracts\WaitsForTasks;
use Filagora\Search\Engines\Meilisearch\FilterCompiler;
use Filagora\Search\Enums\Capability;
use Filagora\Search\Exceptions\MeilisearchTaskFailed;
use Filagora\Search\Support\Document;
use Filagora\Search\Support\Hit;
use Filagora\Search\Support\IndexDefinition;
use Filagora\Search\Support\IndexStats;
use Filagora\Search\Support\SearchQuery;
use Filagora\Search\Support\SearchResult;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;

/**
 * Meilisearch over its HTTP API. Writes are accepted at once and applied later, so changes are
 * tracked as tasks: `syncSettings()` waits for its own, and `waitForTasks()` waits for the rest
 * (tests and reindex use it; ordinary indexing does not need to). Every word of a search must
 * match, and a result total that reaches Meilisearch's counting limit is marked as capped.
 */
class MeilisearchEngine implements SearchEngine, WaitsForTasks
{
    /** @var list<int> */
    private array $pendingTasks = [];

    private readonly FilterCompiler $filters;

    public function __construct(
        private readonly string $host,
        private readonly ?string $key = null,
        private readonly string $prefix = '',
        private readonly int $timeout = 5,
        private readonly int $maxTotalHits = 1000,
        private readonly int $failedTaskLookbackHours = 24,
    ) {
        $this->filters = new FilterCompiler;
    }

    public static function fromConfig(): self
    {
        $config = (array) config('filagora-search.meilisearch', []);

        return new self(
            (string) ($config['host'] ?? 'http://localhost:7700'),
            isset($config['key']) ? (string) $config['key'] : null,
            (string) ($config['prefix'] ?? ''),
            (int) ($config['timeout'] ?? 5),
            (int) ($config['max_total_hits'] ?? 1000),
            (int) config('filagora-search.health.failed_task_lookback_hours', 24),
        );
    }

    public function name(): string
    {
        return 'meilisearch';
    }

    public function syncSettings(IndexDefinition $index): void
    {
        $uid = $this->uid($index);

        if ($this->request()->get($this->url("/indexes/{$uid}"))->status() === 404) {
            $this->waitForTask($this->enqueue('post', '/indexes', ['uid' => $uid, 'primaryKey' => $index->primaryKey]));
        }

        $this->waitForTask($this->enqueue('patch', "/indexes/{$uid}/settings", [
            'searchableAttributes' => $index->searchable,
            'filterableAttributes' => $index->filterable,
            'sortableAttributes' => $index->sortable,
            'synonyms' => (object) $index->synonyms,
            'pagination' => ['maxTotalHits' => $this->maxTotalHits],
        ]));
    }

    public function index(IndexDefinition $index, iterable $documents): void
    {
        $payload = [];

        foreach ($documents as $document) {
            $payload[] = $this->payload($index, $document);
        }

        if ($payload !== []) {
            $this->pendingTasks[] = $this->enqueue('post', "/indexes/{$this->uid($index)}/documents?primaryKey={$index->primaryKey}", $payload);
        }
    }

    public function update(IndexDefinition $index, iterable $documents): void
    {
        $payload = [];

        foreach ($documents as $document) {
            $payload[] = $this->payload($index, $document);
        }

        if ($payload !== []) {
            $this->pendingTasks[] = $this->enqueue('put', "/indexes/{$this->uid($index)}/documents?primaryKey={$index->primaryKey}", $payload);
        }
    }

    public function remove(IndexDefinition $index, iterable $ids): void
    {
        $ids = [...$ids];

        if ($ids !== []) {
            $this->pendingTasks[] = $this->enqueue('post', "/indexes/{$this->uid($index)}/documents/delete-batch", $ids);
        }
    }

    public function flush(IndexDefinition $index): void
    {
        $this->pendingTasks[] = $this->enqueue('delete', "/indexes/{$this->uid($index)}/documents");
    }

    public function search(IndexDefinition $index, SearchQuery $query): SearchResult
    {
        $options = [
            'q' => $query->text,
            'page' => $query->page,
            'hitsPerPage' => $query->perPage,
            'matchingStrategy' => $query->matching->value,
            'showRankingScore' => true,
        ];

        if ($query->filter !== null) {
            $filter = $this->filters->compile($query->filter, $index);

            if ($filter === false) {
                return new SearchResult([], 0);
            }

            if ($filter !== true) {
                $options['filter'] = $filter;
            }
        }

        if ($query->sort !== []) {
            $options['sort'] = array_map(function ($sort) use ($index): string {
                if (! in_array($sort->field, $index->sortable, true)) {
                    throw new InvalidArgumentException("[{$sort->field}] is not a sortable field of the [{$index->name}] index.");
                }

                return $sort->field.':'.$sort->direction->value;
            }, $query->sort);
        }

        $body = $this->request()->post($this->url("/indexes/{$this->uid($index)}/search"), $options)->throw()->json();
        $total = (int) ($body['totalHits'] ?? 0);

        return new SearchResult(
            array_map(static fn (array $hit): Hit => new Hit($hit[$index->primaryKey], isset($hit['_rankingScore']) ? (float) $hit['_rankingScore'] : null), $body['hits'] ?? []),
            $total,
            $total >= $this->maxTotalHits,
        );
    }

    public function stats(IndexDefinition $index): ?IndexStats
    {
        $uid = $this->uid($index);
        $stats = $this->request()->get($this->url("/indexes/{$uid}/stats"));

        if (! $stats->successful()) {
            return null;
        }

        $failed = $this->request()->get($this->url('/tasks'), [
            'statuses' => 'failed',
            'indexUids' => $uid,
            'afterFinishedAt' => Carbon::now()->subHours($this->failedTaskLookbackHours)->toIso8601String(),
            'limit' => 1,
        ]);

        return new IndexStats(
            (int) $stats->json('numberOfDocuments', 0),
            (bool) $stats->json('isIndexing', false),
            $failed->successful() ? (int) $failed->json('total', 0) : 0,
        );
    }

    public function capabilities(): array
    {
        return [Capability::SORT, Capability::RANGE_FILTER, Capability::MULTI_VALUE_FILTER, Capability::SYNONYMS];
    }

    /**
     * Wait until every change this instance has sent is applied; throws if Meilisearch failed one.
     */
    public function waitForTasks(int $timeoutSeconds = 60): void
    {
        $tasks = $this->pendingTasks;
        $this->pendingTasks = [];

        foreach ($tasks as $task) {
            $this->waitForTask($task, $timeoutSeconds);
        }
    }

    public function waitForTask(int $task, int $timeoutSeconds = 60): void
    {
        $deadline = microtime(true) + $timeoutSeconds;

        do {
            $body = $this->request()->get($this->url("/tasks/{$task}"))->throw()->json();
            $status = (string) ($body['status'] ?? '');

            if ($status === 'succeeded') {
                return;
            }

            if ($status === 'failed' || $status === 'canceled') {
                throw new MeilisearchTaskFailed("Meilisearch task {$task} {$status}: ".($body['error']['message'] ?? 'no reason given'));
            }

            usleep(50_000);
        } while (microtime(true) < $deadline);

        throw new MeilisearchTaskFailed("Meilisearch task {$task} did not finish within {$timeoutSeconds} seconds.");
    }

    /**
     * @param  array<mixed>|null  $body
     */
    private function enqueue(string $method, string $path, ?array $body = null): int
    {
        $response = $this->request()->send(strtoupper($method), $this->url($path), $body === null ? [] : ['json' => $body]);

        $response->throw();

        return (int) $response->json('taskUid');
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(IndexDefinition $index, Document $document): array
    {
        $payload = [...$document->fields, $index->primaryKey => $document->id];

        if ($document->vectors !== null) {
            $payload['_vectors'] = ['default' => $document->vectors];
        }

        return $payload;
    }

    private function uid(IndexDefinition $index): string
    {
        return $this->prefix.$index->name;
    }

    private function url(string $path): string
    {
        return rtrim($this->host, '/').$path;
    }

    private function request(): PendingRequest
    {
        $request = Http::timeout($this->timeout)->acceptJson()->asJson();

        return $this->key !== null && $this->key !== '' ? $request->withToken($this->key) : $request;
    }
}
