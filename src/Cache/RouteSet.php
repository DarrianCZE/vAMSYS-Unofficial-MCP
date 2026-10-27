<?php

declare(strict_types=1);

namespace VamsysMcp\Cache;

/** Result of reading an airline's route set (see RouteCache). */
final class RouteSet
{
    /**
     * @param list<array<string, mixed>> $routes      projected routes read so far
     * @param bool                       $complete    every route of the airline is in $routes
     * @param bool                       $fromCache   served from route_cache without calling vAMSYS
     * @param string|null                $fetchedAt   UTC time the complete set was read
     * @param string|null                $stopReason  why an incomplete scan paused: rate_limit, time_budget or page_limit
     */
    public function __construct(
        public readonly array $routes,
        public readonly bool $complete,
        public readonly bool $fromCache,
        public readonly ?string $fetchedAt,
        public readonly ?string $stopReason,
    ) {
    }

    public function pauseNote(int $ttlSeconds): string
    {
        $why = match ($this->stopReason) {
            'rate_limit' => 'the per-airline request cap was reached',
            'time_budget' => 'the time budget for one call was used up',
            default => 'the page limit for one call was reached',
        };

        return "Scan paused after " . count($this->routes) . " routes because {$why}. Call again (after a minute for the request cap) to continue; "
            . 'progress is kept for ' . intdiv($ttlSeconds, 60) . ' minutes. Results so far are partial.';
    }
}
