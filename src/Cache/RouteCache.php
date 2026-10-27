<?php

declare(strict_types=1);

namespace VamsysMcp\Cache;

use Psr\Clock\ClockInterface;
use VamsysMcp\Exception\RateLimitExceededException;
use VamsysMcp\Sql;
use VamsysMcp\VamsysClient;

/**
 * An airline's full route set (projected, see RouteRecord), cached in
 * route_cache for a short TTL so counts and searches don't re-read vAMSYS.
 *
 * Reading the full set is a resumable scan: each call reads pages until the
 * set is complete, the time budget is used up (shared hosting kills long PHP
 * requests) or the per-airline request cap is reached. It never waits for the
 * rate limiter and never exceeds the cap. Progress (routes so far + cursor)
 * is saved, so the next call continues instead of starting over.
 */
final class RouteCache
{
    /** @var \Closure(): float */
    private readonly \Closure $time;

    /**
     * @param null|\Closure(): float $time seconds clock for the time budget (injectable for tests)
     */
    public function __construct(
        private readonly \PDO $pdo,
        private readonly VamsysClient $client,
        private readonly ClockInterface $clock,
        private readonly int $ttlSeconds = 600,
        private readonly int $maxPagesPerCall = 100,
        private readonly float $timeBudgetSeconds = 20.0,
        ?\Closure $time = null,
    ) {
        $this->time = $time ?? static fn (): float => microtime(true);
    }

    /**
     * The cached complete set if it is younger than the TTL, without calling vAMSYS.
     *
     * @return RouteSet|null
     */
    public function fresh(string $airline): ?RouteSet
    {
        $row = $this->load($airline);
        if ($row === null || (int) $row['complete'] !== 1 || Sql::olderThan($row['fetched_at'], $this->ttlSeconds, $this->clock->now())) {
            return null;
        }

        return new RouteSet(self::decode($row['data']), true, true, (string) $row['fetched_at'], null);
    }

    /**
     * The full set: from the cache when fresh (unless $refresh), otherwise by
     * scanning (or continuing an unfinished scan) within the budget.
     */
    public function get(string $airline, bool $refresh = false): RouteSet
    {
        if (!$refresh && ($cached = $this->fresh($airline)) !== null) {
            return $cached;
        }

        $row = $this->load($airline);
        $resume = !$refresh && $row !== null && (int) $row['complete'] !== 1 && !Sql::olderThan($row['started_at'], $this->ttlSeconds, $this->clock->now());
        $routes = $resume ? self::decode($row['data']) : [];
        $cursor = $resume ? $row['next_cursor'] : null;
        $startedAt = $resume ? (string) $row['started_at'] : Sql::now($this->clock->now());

        $deadline = ($this->time)() + $this->timeBudgetSeconds;
        $complete = false;
        $stop = null;
        for ($pages = 0; ; ++$pages) {
            if ($pages >= $this->maxPagesPerCall) {
                $stop = 'page_limit';
                break;
            }
            if ($pages > 0 && ($this->time)() >= $deadline) {
                $stop = 'time_budget';
                break;
            }
            $query = ['sort' => 'id'] + ($cursor !== null ? ['page[cursor]' => $cursor] : []);
            try {
                $body = $this->client->get($airline, '/routes', $query + ['page[size]' => $this->client->pageSize()], 0);
            } catch (RateLimitExceededException) {
                $stop = 'rate_limit';
                break;
            }
            foreach ((array) ($body['data'] ?? []) as $route) {
                $routes[] = RouteRecord::project((array) $route);
            }
            $next = $body['meta']['next_cursor'] ?? null;
            if (!is_string($next) || $next === '') {
                $complete = true;
                break;
            }
            $cursor = $next;
        }

        $fetchedAt = $complete ? Sql::now($this->clock->now()) : null;
        Sql::upsert($this->pdo, 'route_cache', 'airline', [
            'airline' => $airline,
            'started_at' => $startedAt,
            'fetched_at' => $fetchedAt,
            'complete' => $complete ? 1 : 0,
            'next_cursor' => $complete ? null : $cursor,
            'route_count' => count($routes),
            'data' => (string) json_encode($routes, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);

        return new RouteSet($routes, $complete, false, $fetchedAt, $stop);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function load(string $airline): ?array
    {
        $stmt = $this->pdo->prepare('SELECT started_at, fetched_at, complete, next_cursor, data FROM route_cache WHERE airline = :a');
        $stmt->execute(['a' => $airline]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function decode(mixed $data): array
    {
        $routes = json_decode((string) $data, true);

        return is_array($routes) ? array_values($routes) : [];
    }
}
