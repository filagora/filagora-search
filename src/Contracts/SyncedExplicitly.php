<?php

declare(strict_types=1);

namespace Filagora\Search\Contracts;

/**
 * An index that is filled only by a run someone starts (an ingest, a backfill), never by saving a
 * row. It is still registered, so it can be searched and its settings applied, but `ModelSync` leaves
 * it alone: a save of one of its rows queues no sync and, above all, makes no embedding call.
 */
interface SyncedExplicitly extends SearchIndex {}
