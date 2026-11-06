<?php

declare(strict_types=1);

namespace Filagora\Search\Tests\Support;

use Filagora\Search\Contracts\BatchesDocuments;
use Filagora\Search\Support\Document;
use Filagora\Search\Support\IndexDefinition;
use Illuminate\Database\Eloquent\Collection;

/**
 * Users with a vector each, made one call per chunk (as an embedding service would be asked), and
 * remembering how many rows each call was given.
 */
class BatchedUserIndex extends UserIndex implements BatchesDocuments
{
    /** @var list<int> */
    public array $batchSizes = [];

    public function definition(): IndexDefinition
    {
        return new IndexDefinition('users', ['name'], ['email'], vectorDimensions: 2);
    }

    public function toDocuments(Collection $models): array
    {
        $this->batchSizes[] = $models->count();

        return $models->map(fn ($model): Document => new Document($model->getKey(), ['name' => $model->getAttribute('name')], [1.0, (float) $model->getKey()]))->all();
    }
}
