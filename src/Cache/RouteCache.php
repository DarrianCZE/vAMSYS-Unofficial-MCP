<?php

declare(strict_types=1);

namespace VamsysMcp\Cache;

use Psr\Clock\ClockInterface;
use VamsysMcp\Exception\RateLimitExceededException;
use VamsysMcp\Sql;
use VamsysMcp\VamsysClient;

/**
 * Route sets (projected, see RouteRecord) cached in route_scan per query: the
 * airline's full set (no filters) and the subsets vAMSYS returns for
 * server-side filters (RouteFilter::serverQueries()).
 *
 * Reading a set is a resumable scan: each call reads pages until the set is
 * complete, the time budget would be exceeded (MCP clients time out after
 * ~25-30 s; shared hosting kills long PHP requests) or the per-airline request
 * cap is reached. It never waits for the rate limiter and never exceeds the
 * cap. Progress (routes so far + cursor) is saved, so the next call continues
 * instead of starting over. The last complete set keeps answering until a
 * new scan completes. A per-set lock (ScanLock) keeps two calls from reading
 * the same set at once: the second waits, then continues the first's work.
 */
final class RouteCache
{
    /** Filtered sets not read for this long are deleted. */
    private const int FILTERED_MAX_AGE = 86400;
    /** Suggested wait when another call holds the scan lock. */
    private const int BUSY_RETRY_SECONDS = 5;

    /** @var \Closure(): float */
    private readonly \Closure $time;
    private readonly ScanLock $lock;

    /**
     * @param null|\Closure(): float $time seconds clock for the time budget (injectable for tests)
     */
    public function __construct(
        private readonly \PDO $pdo,
        private readonly VamsysClient $client,
        private readonly ClockInterface $clock,
        private readonly int $ttlSeconds = 600,
        private readonly int $maxPagesPerCall = 100,
        private readonly float $timeBudgetSeconds = 15.0,
        ?\Closure $time = null,
        ?ScanLock $lock = null,
    ) {
        $this->time = $time ?? static fn (): float => microtime(true);
        $this->lock = $lock ?? new ScanLock($pdo);
    }

    public function ttlSeconds(): int
    {
        return $this->ttlSeconds;
    }

    /**
     * Fresh complete routes for $filter without calling vAMSYS: the full set,
     * else (with a filter) every one of its server-side query sets.
     */
    public function fresh(string $airline, ?RouteFilter $filter = null): ?RouteSet
    {
        $full = $this->freshFrom($this->load($airline, ''), false);
        if ($full !== null || $filter === null || ($queries = $filter->serverQueries()) === []) {
            return $full;
        }
        $sets = [];
        foreach ($queries as $query) {
            $set = $this->freshFrom($this->load($airline, self::key($query)), true);
            if ($set === null) {
                return null;
            }
            $sets[] = $set;
        }

        return self::merge($sets);
    }

    /**
     * The routes $filter needs: from the cache when fresh (unless $refresh),
     * else by scanning its server-side queries, or the full set when it has
     * none, all within one time budget.
     */
    public function forFilter(string $airline, RouteFilter $filter, bool $refresh = false): RouteSet
    {
        if (!$refresh && ($cached = $this->fresh($airline, $filter)) !== null) {
            return $cached;
        }
        $deadline = ($this->time)() + $this->timeBudgetSeconds;
        $queries = $filter->serverQueries() ?: [[]];

        return self::merge(array_map(fn (array $query): RouteSet => $this->scan($airline, $query, $refresh, $deadline), $queries));
    }

    /**
     * One set: the full set for an empty $query, else the routes vAMSYS returns
     * for these filter[...] parameters. With $refresh the cached complete set
     * is not used, but an unfinished scan still continues (so calling again
     * with the same arguments makes progress).
     *
     * @param array<string, string> $query
     * @param float|null            $deadline time() by which to stop; default now + the time budget
     */
    public function scan(string $airline, array $query = [], bool $refresh = false, ?float $deadline = null): RouteSet
    {
        $key = self::key($query);
        $filtered = $query !== [];
        $deadline ??= ($this->time)() + $this->timeBudgetSeconds;
        if (!$refresh && ($cached = $this->freshFrom($this->load($airline, $key), $filtered)) !== null) {
            return $cached;
        }

        $lockName = "scan:{$airline}:{$key}";
        if (!$this->lock->acquire($lockName, $deadline - ($this->time)() - 1.0)) {
            $row = $this->load($airline, $key);

            return (!$refresh ? $this->freshFrom($row, $filtered) : null)
                ?? $this->partial($row, $filtered, 'busy', self::BUSY_RETRY_SECONDS);
        }
        try {
            // Another call may have finished or advanced this scan while we waited.
            $row = $this->load($airline, $key);
            if (!$refresh && ($cached = $this->freshFrom($row, $filtered)) !== null) {
                return $cached;
            }
            $resume = $this->scanInProgress($row);
            $routes = $resume ? self::decode($row['scan_data']) : [];
            $cursor = $resume && is_string($row['scan_cursor']) ? $row['scan_cursor'] : null;
            $startedAt = $resume ? (string) $row['scan_started_at'] : Sql::now($this->clock->now());

            [$complete, $cursor, $stop, $retryAfter] = $this->read($airline, $query, $cursor, $routes, $deadline);

            if ($complete) {
                $fetchedAt = Sql::now($this->clock->now());
                Sql::upsert($this->pdo, 'route_scan', ['airline', 'query_key'], [
                    'airline' => $airline, 'query_key' => $key,
                    'fetched_at' => $fetchedAt, 'route_count' => count($routes), 'data' => self::encode($routes),
                    'scan_started_at' => null, 'scan_cursor' => null, 'scan_data' => null,
                ]);
                if ($filtered) {
                    $this->pruneFiltered($airline);
                }

                return new RouteSet($routes, true, false, $fetchedAt, null, filtered: $filtered);
            }
            Sql::upsert($this->pdo, 'route_scan', ['airline', 'query_key'], [
                'airline' => $airline, 'query_key' => $key,
                'scan_started_at' => $startedAt, 'scan_cursor' => $cursor, 'scan_data' => self::encode($routes),
            ]);

            return new RouteSet($routes, false, false, null, $stop, $retryAfter ?? 0, $filtered, self::progress(count($routes), $row));
        } finally {
            $this->lock->release($lockName);
        }
    }

    /**
     * Read pages of /routes from $cursor, appending projected routes, until the
     * end, the page limit, the request cap (never waited for) or the point
     * where the next page would probably end after $deadline.
     *
     * @param array<string, string>      $filters vAMSYS filter[...] parameters
     * @param list<array<string, mixed>> $routes
     *
     * @return array{0: bool, 1: string|null, 2: string|null, 3: int|null} complete, cursor to continue from, stop reason, retry-after seconds
     */
    private function read(string $airline, array $filters, ?string $cursor, array &$routes, float $deadline): array
    {
        $slowest = 0.0;
        for ($pages = 0; ; ++$pages) {
            if ($pages >= $this->maxPagesPerCall) {
                return [false, $cursor, 'page_limit', 0];
            }
            $started = ($this->time)();
            if ($pages > 0 && $started + $slowest >= $deadline) {
                return [false, $cursor, 'time_budget', 0];
            }
            $query = ['sort' => 'id'] + $filters + ($cursor !== null ? ['page[cursor]' => $cursor] : []);
            try {
                $body = $this->client->get($airline, '/routes', $query + ['page[size]' => $this->client->pageSize()], 0);
            } catch (RateLimitExceededException $e) {
                return [false, $cursor, 'rate_limit', $e->retryAfterSeconds];
            }
            $slowest = max($slowest, ($this->time)() - $started);
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
    private function freshFrom(?array $row, bool $filtered): ?RouteSet
    {
        if ($row === null || $row['data'] === null || Sql::olderThan($row['fetched_at'], $this->ttlSeconds, $this->clock->now())) {
            return null;
        }

        return new RouteSet(self::decode($row['data']), true, true, (string) $row['fetched_at'], null, filtered: $filtered);
    }

    /**
     * What a call that could not get the lock can report: the other call's progress so far.
     *
     * @param array<string, mixed>|null $row
     */
    private function partial(?array $row, bool $filtered, string $stop, int $retryAfter): RouteSet
    {
        $routes = $this->scanInProgress($row) ? self::decode($row['scan_data']) : [];

        return new RouteSet($routes, false, false, null, $stop, $retryAfter, $filtered, self::progress(count($routes), $row));
    }

    /**
     * @param array<string, mixed>|null $row
     */
    private function scanInProgress(?array $row): bool
    {
        return $row !== null && $row['scan_data'] !== null && !Sql::olderThan($row['scan_started_at'], $this->ttlSeconds, $this->clock->now());
    }

    /**
     * Share read so far, estimated from the size of the last complete set.
     *
     * @param array<string, mixed>|null $row
     */
    private static function progress(int $read, ?array $row): ?float
    {
        $total = (int) ($row['route_count'] ?? 0);

        return $total > 0 ? min(0.99, round($read / $total, 2)) : null;
    }

    /**
     * @param list<RouteSet> $sets
     */
    private static function merge(array $sets): RouteSet
    {
        if (count($sets) === 1) {
            return $sets[0];
        }
        $routes = [];
        foreach ($sets as $set) {
            foreach ($set->routes as $route) {
                $routes[$route['id']] = $route;
            }
        }
        ksort($routes);
        $incomplete = array_values(array_filter($sets, static fn (RouteSet $s): bool => !$s->complete));
        $complete = $incomplete === [];
        $fetched = array_filter(array_map(static fn (RouteSet $s): ?string => $s->fetchedAt, $sets));

        return new RouteSet(
            array_values($routes),
            $complete,
            array_reduce($sets, static fn (bool $all, RouteSet $s): bool => $all && $s->fromCache, true),
            $complete && $fetched !== [] ? min($fetched) : null,
            $incomplete[0]->stopReason ?? null,
            $complete ? null : max(array_map(static fn (RouteSet $s): int => $s->retryAfterSeconds ?? 0, $incomplete)),
            true,
        );
    }

    private function pruneFiltered(string $airline): void
    {
        $before = Sql::now($this->clock->now()->modify('-' . self::FILTERED_MAX_AGE . ' seconds'));
        $this->pdo->prepare("DELETE FROM route_scan WHERE airline = :a AND query_key <> ''
            AND (fetched_at IS NULL OR fetched_at < :b) AND (scan_started_at IS NULL OR scan_started_at < :b2)")
            ->execute(['a' => $airline, 'b' => $before, 'b2' => $before]);
    }

    /**
     * @param array<string, string> $query
     */
    private static function key(array $query): string
    {
        if ($query === []) {
            return '';
        }
        ksort($query);

        return sha1(http_build_query($query));
    }

    /**
     * @return array<string, mixed>|null
     */
    private function load(string $airline, string $key): ?array
    {
        $stmt = $this->pdo->prepare('SELECT fetched_at, route_count, data, scan_started_at, scan_cursor, scan_data FROM route_scan WHERE airline = :a AND query_key = :k');
        $stmt->execute(['a' => $airline, 'k' => $key]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    /**
     * @param list<array<string, mixed>> $routes
     */
    private static function encode(array $routes): string
    {
        return (string) json_encode($routes, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
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
