<?php

declare(strict_types=1);

namespace App\Services\Ingestion;

use App\Data\HistoricalLine;

/**
 * Implemented by sources that can supply closing lines for seasons we did
 * not snapshot ourselves. Without this, a sport has no market benchmark for
 * history and its backtest verdict stays "not enough data yet".
 */
interface ProvidesHistoricalLines
{
    /** @return iterable<int, HistoricalLine> */
    public function historicalLines(int $season): iterable;
}
