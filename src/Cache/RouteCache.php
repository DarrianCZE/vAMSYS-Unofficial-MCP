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
 *
 * matching() reads only the routes vAMSYS returns for server-side filters,
 * under the same limits. It is not cached: such sets are small, and reading
 * them again is cheaper than reading the full set once.
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
     */
    public function fresh(string $airline): ?RouteSet
    {
        return $this->freshFrom($this->load($airline));
    }

    /**
     * The full set: from the cache when fresh (unless $refresh), otherwise by
     * scanning (or continuing an unfinished scan) within the budget.
     */
    public function get(string $airline, bool $refresh = false): RouteSet
    {
        $row = $this->load($airline);
        if (!$refresh && ($cached = $this->freshFrom($row)) !== null) {
            return $cached;
        }

        $resume = !$refresh && $row !== null && (int) $row['complete'] !== 1 && !Sql::olderThan($row['started_at'], $this->ttlSeconds, $this->clock->now());
        $routes = $resume ? self::decode($row['data']) : [];
        $cursor = $resume ? $row['next_cursor'] : null;
        $startedAt = $resume ? (string) $row['started_at'] : Sql::now($this->clock->now());

        [$complete, $cursor, $stop, $retryAfter] = $this->read($airline, [], $cursor, $routes);

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

        return new RouteSet($routes, $complete, false, $fetchedAt, $stop, $retryAfter);
    }

    /**
     * The routes vAMSYS returns for the filter's server-side part
     * (RouteFilter::toQuery(), which must not be empty), read now within the
     * same budget as a full scan. Not cached, not resumable.
     */
    public function matching(string $airline, RouteFilter $filter): RouteSet
    {
        $query = $filter->toQuery();
        if ($query === []) {
            throw new \LogicException('RouteCache::matching() needs at least one server-side filter.');
        }
        $routes = [];
        [$complete, , $stop, $retryAfter] = $this->read($airline, $query, null, $routes);

        return new RouteSet($routes, $complete, false, $complete ? Sql::now($this->clock->now()) : null, $stop, $retryAfter, filtered: true);
    }

    /**
     * Read pages of /routes from $cursor, appending projected routes, until the
     * end, the page limit, the time budget or the request cap (never waited for).
     *
     * @param array<string, string>      $filters vAMSYS filter[...] parameters
     * @param list<array<string, mixed>> $routes
     *
     * @return array{0: bool, 1: string|null, 2: string|null, 3: int|null} complete, cursor to continue from, stop reason, retry-after seconds
     */
    private function read(string $airline, array $filters, ?string $cursor, array &$routes): array
    {
        $deadline = ($this->time)() + $this->timeBudgetSeconds;
        for ($pages = 0; ; ++$pages) {
            if ($pages >= $this->maxPagesPerCall) {
                return [false, $cursor, 'page_limit', null];
            }
            if ($pages > 0 && ($this->time)() >= $deadline) {
                return [false, $cursor, 'time_budget', null];
            }
            $query = ['sort' => 'id'] + $filters + ($cursor !== null ? ['page[cursor]' => $cursor] : []);
            try {
                $body = $this->client->get($airline, '/routes', $query + ['page[size]' => $this->client->pageSize()], 0);
            } catch (RateLimitExceededException $e) {
                return [false, $cursor, 'rate_limit', $e->retryAfterSeconds];
            }
            foreach ((array) ($body['data'] ?? []) as $route) {
                $routes[] = RouteRecord::project((array) $route);
            }
            $next = $body['meta']['next_cursor'] ?? null;
            if (!is_string($next) || $next === '') {
                return [true, null, null, null];
            }
            $cursor = $next;
        }
    }

    /**
     * @param array<string, mixed>|null $row
     */
    private function freshFrom(?array $row): ?RouteSet
    {
        if ($row === null || (int) $row['complete'] !== 1 || Sql::olderThan($row['fetched_at'], $this->ttlSeconds, $this->clock->now())) {
            return null;
        }

        return new RouteSet(self::decode($row['data']), true, true, (string) $row['fetched_at'], null);
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
