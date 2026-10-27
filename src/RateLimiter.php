<?php

declare(strict_types=1);

namespace VamsysMcp;

use Psr\Clock\ClockInterface;
use VamsysMcp\Exception\RateLimitExceededException;

/**
 * DB-backed fixed-window (one minute) request counter per airline.
 *
 * Every PHP process (STDIO, each PHP-FPM request) shares the same row, so the
 * cap holds across concurrent requests. Call acquire() before every request
 * to vAMSYS; it either returns (a slot was taken) or throws.
 */
final class RateLimiter
{
    private const int WINDOW_SECONDS = 60;

    /** @var \Closure(float): void */
    private readonly \Closure $sleep;

    /**
     * @param int                 $maxWaitSeconds how long acquire() may block waiting for the next window
     * @param null|\Closure(float): void $sleep  injectable for tests; defaults to usleep()
     */
    public function __construct(
        private readonly \PDO $pdo,
        private readonly ClockInterface $clock,
        private readonly int $maxWaitSeconds = 10,
        ?\Closure $sleep = null,
    ) {
        $this->sleep = $sleep ?? static function (float $seconds): void {
            usleep((int) ceil($seconds * 1_000_000));
        };
    }

    /**
     * Take one request slot for the airline, waiting for the next window if
     * the cap is reached and the wait fits within maxWaitSeconds.
     *
     * @throws RateLimitExceededException
     */
    public function acquire(string $airline, int $cap): void
    {
        $waited = 0.0;
        while (true) {
            $now = $this->clock->now();
            $windowStart = intdiv($now->getTimestamp(), self::WINDOW_SECONDS) * self::WINDOW_SECONDS;

            if ($this->increment($airline, $windowStart) <= $cap) {
                return;
            }

            $secondsLeft = max(0.0, $windowStart + self::WINDOW_SECONDS - ((float) $now->format('U.u')));
            // Sleep slightly past the boundary so the next attempt lands in the new window.
            $sleepFor = $secondsLeft + 0.05;
            if ($waited + $sleepFor > $this->maxWaitSeconds) {
                throw new RateLimitExceededException(sprintf(
                    "Request cap of %d requests/minute for airline '%s' reached (the vAMSYS limit is shared with "
                    . 'all other OAuth clients of this airline). Try again in about %d seconds.',
                    $cap,
                    $airline,
                    max(1, (int) ceil($secondsLeft)),
                ));
            }
            ($this->sleep)($sleepFor);
            $waited += $sleepFor;
        }
    }

    /**
     * Atomically count one request in the current window and return the new
     * count. A row from an older window is reset; a stale (older) window from
     * a lagging clock never moves the row backwards.
     */
    private function increment(string $airline, int $windowStart): int
    {
        $window = gmdate('Y-m-d H:i:s', $windowStart);

        // MariaDB/MySQL evaluate ON DUPLICATE KEY UPDATE assignments left to
        // right, so req_count must be computed before window_start changes.
        $sql = match ($this->pdo->getAttribute(\PDO::ATTR_DRIVER_NAME)) {
            'mysql' => 'INSERT INTO rate_window (airline, window_start, req_count) VALUES (:airline, :window, 1)
                ON DUPLICATE KEY UPDATE
                    req_count = IF(VALUES(window_start) > window_start, 1, req_count + 1),
                    window_start = GREATEST(window_start, VALUES(window_start))',
            // Used by the test suite only.
            'sqlite' => 'INSERT INTO rate_window (airline, window_start, req_count) VALUES (:airline, :window, 1)
                ON CONFLICT (airline) DO UPDATE SET
                    req_count = CASE WHEN excluded.window_start > window_start THEN 1 ELSE req_count + 1 END,
                    window_start = MAX(window_start, excluded.window_start)',
            default => throw new \LogicException('Unsupported database driver for RateLimiter.'),
        };

        // The upsert takes a row lock that is held until commit, so the
        // SELECT below reads this request's own increment.
        $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare($sql)->execute(['airline' => $airline, 'window' => $window]);
            $select = $this->pdo->prepare('SELECT req_count FROM rate_window WHERE airline = :airline');
            $select->execute(['airline' => $airline]);
            $count = (int) $select->fetchColumn();
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }

        return $count;
    }
}
