<?php

declare(strict_types=1);

namespace VamsysMcp\Cache;

use Psr\Clock\ClockInterface;
use VamsysMcp\Exception\RateLimitExceededException;
use VamsysMcp\Sql;
use VamsysMcp\VamsysClient;

/**
 * Airport ID ↔ ICAO/IATA/name per airline, cached in airport_cache.
 *
 * The full airport list (GET /airports, paged by cursor through the rate
 * limiter) is needed to show routes with codes. It is reloaded when the cache
 * is older than the TTL, or once per request when an ID is missing. Codes
 * given as filters never trigger a reload: a code that is not in the fresh
 * cache costs one filtered request (see findByCode). A cooldown keeps an
 * airport that no longer exists from triggering a reload on every call. A
 * reload cut short by the rate cap keeps what it got; stale rows are removed
 * only after a complete reload.
 */
final class AirportCache
{
    private const int MISS_RELOAD_COOLDOWN = 300;

    /** @var array<string, bool> airline => whether this request already reloaded it (true = complete) */
    private array $reloaded = [];

    public function __construct(
        private readonly \PDO $pdo,
        private readonly VamsysClient $client,
        private readonly ClockInterface $clock,
        private readonly int $ttlSeconds = 86400,
        private readonly int $maxPages = 100,
    ) {
    }

    /**
     * @param list<int> $ids
     *
     * @return array<int, array{id: int, icao: string|null, iata: string|null, name: string}> found airports by ID
     */
    public function lookup(string $airline, array $ids): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if ($ids === []) {
            return [];
        }
        $this->ensureFresh($airline);
        $found = $this->rowsById($airline, $ids);
        if (array_diff($ids, array_keys($found)) !== [] && $this->mayReloadOnMiss($airline)) {
            $this->reload($airline);
            $found = $this->rowsById($airline, $ids);
        }

        return $found;
    }

    /**
     * Resolve an ICAO (4 letters) or IATA (3 letters) code. Served from the
     * cache while the airport list is fresh; otherwise (or when the code is
     * not cached) one filtered /airports request answers it, so a filter
     * never costs a full reload of the list. That answer is not stored: the
     * list's freshness is judged by full reloads only.
     *
     * @return array{id: int, icao: string|null, iata: string|null, name: string}|null
     *
     * @throws \InvalidArgumentException for a malformed code
     */
    public function findByCode(string $airline, string $code): ?array
    {
        $code = strtoupper(trim($code));
        $column = match (true) {
            preg_match('/^[A-Z0-9]{4}$/', $code) === 1 => 'icao',
            preg_match('/^[A-Z0-9]{3}$/', $code) === 1 => 'iata',
            default => throw new \InvalidArgumentException("Airport code '{$code}' must be a 4-letter ICAO or 3-letter IATA code."),
        };
        if ($this->isFresh($airline) && ($row = $this->rowByCode($airline, $column, $code)) !== null) {
            return $row;
        }

        $body = $this->client->get($airline, '/airports', ["filter[{$column}]" => $code, 'sort' => 'id', 'page[size]' => 10]);
        foreach ((array) ($body['data'] ?? []) as $airport) {
            $airport = (array) $airport;
            if (isset($airport['id']) && self::code($airport[$column] ?? null) === $code) {
                return ['id' => (int) $airport['id'], 'icao' => self::code($airport['icao'] ?? null), 'iata' => self::code($airport['iata'] ?? null), 'name' => mb_substr((string) ($airport['name'] ?? ''), 0, 255)];
            }
        }

        return null;
    }

    /**
     * Reload the airline's airport list. Returns true if it was read completely.
     */
    public function reload(string $airline): bool
    {
        $started = Sql::now($this->clock->now());
        $complete = false;
        // No surrounding transaction: the rate limiter runs its own per request.
        try {
            $pages = $this->client->paginate($airline, '/airports', ['sort' => 'id'], $this->maxPages);
            foreach ($pages as $airport) {
                if (!isset($airport['id'])) {
                    continue;
                }
                Sql::upsert($this->pdo, 'airport_cache', ['airline', 'airport_id'], [
                    'airline' => $airline,
                    'airport_id' => (int) $airport['id'],
                    'icao' => self::code($airport['icao'] ?? null),
                    'iata' => self::code($airport['iata'] ?? null),
                    'name' => mb_substr((string) ($airport['name'] ?? ''), 0, 255),
                    'fetched_at' => $started,
                ]);
            }
            $complete = $pages->getReturn();
            if ($complete) {
                $this->pdo->prepare('DELETE FROM airport_cache WHERE airline = :a AND fetched_at < :s')->execute(['a' => $airline, 's' => $started]);
            }
        } catch (RateLimitExceededException) {
            $complete = false; // keep the rows read so far
        }
        $this->reloaded[$airline] = $complete;

        return $complete;
    }

    private function ensureFresh(string $airline): void
    {
        if (!isset($this->reloaded[$airline]) && !$this->isFresh($airline)) {
            $this->reload($airline);
        }
    }

    /** The cached list is non-empty and was fully read within the TTL. */
    private function isFresh(string $airline): bool
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) AS n, MIN(fetched_at) AS oldest FROM airport_cache WHERE airline = :a');
        $stmt->execute(['a' => $airline]);
        $row = (array) $stmt->fetch(\PDO::FETCH_ASSOC);

        return (int) ($row['n'] ?? 0) > 0 && !Sql::olderThan($row['oldest'] ?? null, $this->ttlSeconds, $this->clock->now());
    }

    private function mayReloadOnMiss(string $airline): bool
    {
        if (isset($this->reloaded[$airline])) {
            return false;
        }
        $stmt = $this->pdo->prepare('SELECT MAX(fetched_at) FROM airport_cache WHERE airline = :a');
        $stmt->execute(['a' => $airline]);
        $newest = $stmt->fetchColumn();

        return Sql::olderThan(is_string($newest) ? $newest : null, self::MISS_RELOAD_COOLDOWN, $this->clock->now());
    }

    /**
     * @param list<int> $ids
     *
     * @return array<int, array{id: int, icao: string|null, iata: string|null, name: string}>
     */
    private function rowsById(string $airline, array $ids): array
    {
        $placeholders = implode(', ', array_fill(0, count($ids), '?'));
        $stmt = $this->pdo->prepare("SELECT airport_id, icao, iata, name FROM airport_cache WHERE airline = ? AND airport_id IN ({$placeholders})");
        $stmt->execute([$airline, ...$ids]);
        $out = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $r) {
            $out[(int) $r['airport_id']] = self::row($r);
        }

        return $out;
    }

    /**
     * @return array{id: int, icao: string|null, iata: string|null, name: string}|null
     */
    private function rowByCode(string $airline, string $column, string $code): ?array
    {
        $stmt = $this->pdo->prepare("SELECT airport_id, icao, iata, name FROM airport_cache WHERE airline = :a AND {$column} = :c ORDER BY airport_id LIMIT 1");
        $stmt->execute(['a' => $airline, 'c' => $code]);
        $r = $stmt->fetch(\PDO::FETCH_ASSOC);

        return is_array($r) ? self::row($r) : null;
    }

    /**
     * @param array<string, mixed> $r
     *
     * @return array{id: int, icao: string|null, iata: string|null, name: string}
     */
    private static function row(array $r): array
    {
        return ['id' => (int) $r['airport_id'], 'icao' => $r['icao'] !== null ? (string) $r['icao'] : null, 'iata' => $r['iata'] !== null ? (string) $r['iata'] : null, 'name' => (string) $r['name']];
    }

    private static function code(mixed $code): ?string
    {
        $code = is_string($code) ? strtoupper(trim($code)) : '';

        return $code === '' ? null : substr($code, 0, 8);
    }
}
