<?php

declare(strict_types=1);

namespace VamsysMcp\Cache;

/** Result of reading an airline's route set, or the routes matching a filter (see RouteCache). */
final class RouteSet
{
    /**
     * @param list<array<string, mixed>> $routes            projected routes read so far
     * @param bool                       $complete          every route of the set is in $routes
     * @param bool                       $fromCache         served from route_cache without calling vAMSYS
     * @param string|null                $fetchedAt         UTC time the complete set was read
     * @param string|null                $stopReason        why an incomplete scan paused: rate_limit, time_budget or page_limit
     * @param int|null                   $retryAfterSeconds after a rate_limit pause: when the request cap allows the next request
     * @param bool                       $filtered          only routes matching server-side filters (vAMSYS filter[...]), not the full set
     */
    public function __construct(
        public readonly array $routes,
        public readonly bool $complete,
        public readonly bool $fromCache,
        public readonly ?string $fetchedAt,
        public readonly ?string $stopReason,
        public readonly ?int $retryAfterSeconds = null,
        public readonly bool $filtered = false,
    ) {
    }

    public function pauseNote(int $ttlSeconds): string
    {
        $why = match ($this->stopReason) {
            'rate_limit' => 'the per-airline request cap was reached',
            'time_budget' => 'the time budget for one call was used up',
            default => 'the page limit for one call was reached',
        };
        $when = $this->retryAfterSeconds !== null ? "in about {$this->retryAfterSeconds} seconds" : 'now';
        $note = 'Scan paused after ' . count($this->routes) . " routes because {$why}. Results so far are partial. ";

        // A filtered scan is not saved; only the full route set is resumable.
        return $note . ($this->filtered
            ? "Narrow the filters (e.g. give both dep and arr) or call again {$when}; it starts over."
            : "Call again {$when} to continue; progress is kept for " . intdiv($ttlSeconds, 60) . ' minutes.');
    }
}
