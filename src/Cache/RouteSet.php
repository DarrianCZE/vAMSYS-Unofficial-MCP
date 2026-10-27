<?php

declare(strict_types=1);

namespace VamsysMcp\Cache;

/** Result of reading an airline's route set, or the routes matching a filter (see RouteCache). */
final class RouteSet
{
    /**
     * @param list<array<string, mixed>> $routes            projected routes read so far
     * @param bool                       $complete          every route of the set is in $routes
     * @param bool                       $fromCache         served from route_scan without calling vAMSYS
     * @param string|null                $fetchedAt         UTC time the complete set was read (the oldest part, for a merged set)
     * @param string|null                $stopReason        why an incomplete scan paused: rate_limit, time_budget, page_limit or busy (another call is scanning it)
     * @param int|null                   $retryAfterSeconds after a pause: when calling again makes sense (0 = at once)
     * @param bool                       $filtered          only routes matching server-side filters (vAMSYS filter[...]), not the full set
     * @param float|null                 $progress          estimated share already read (from the last complete scan's size), if known
     */
    public function __construct(
        public readonly array $routes,
        public readonly bool $complete,
        public readonly bool $fromCache,
        public readonly ?string $fetchedAt,
        public readonly ?string $stopReason,
        public readonly ?int $retryAfterSeconds = null,
        public readonly bool $filtered = false,
        public readonly ?float $progress = null,
    ) {
    }

    /**
     * Machine-readable fields for a paused scan plus a short note, for tool output.
     *
     * @return array<string, mixed>
     */
    public function pauseFields(int $ttlSeconds): array
    {
        $why = match ($this->stopReason) {
            'rate_limit' => 'the per-airline request cap was reached',
            'time_budget' => 'the time budget for one call was used up',
            'busy' => 'another call is reading the same routes',
            default => 'the page limit for one call was reached',
        };
        $retry = $this->retryAfterSeconds ?? 0;

        return array_filter([
            'resumable' => true,
            'retry_after_seconds' => $retry,
            'progress' => $this->progress,
            'note' => 'Scan paused after ' . count($this->routes) . " routes because {$why}; results so far are partial. "
                . 'Call again ' . ($retry > 0 ? "in {$retry} seconds" : 'now') . ' with the same arguments to continue; '
                . 'progress is kept for ' . intdiv($ttlSeconds, 60) . ' minutes. Do not start other scans for this airline meanwhile.',
        ], static fn (mixed $v): bool => $v !== null);
    }
}
