<?php

declare(strict_types=1);

namespace VamsysMcp\Admin;

/**
 * Limits failed admin logins per client IP and globally, in 15-minute windows
 * stored in admin_throttle (works across PHP-FPM workers).
 */
final class LoginThrottle
{
    private const int WINDOW = 900;
    private const int PER_IP = 5;
    private const int GLOBAL = 25;

    public function __construct(private readonly \PDO $pdo)
    {
    }

    public function isBlocked(string $ip): bool
    {
        return $this->count($this->ipBucket($ip)) >= self::PER_IP || $this->count('global') >= self::GLOBAL;
    }

    public function recordFailure(string $ip): void
    {
        foreach ([$this->ipBucket($ip), 'global'] as $bucket) {
            $window = gmdate('Y-m-d H:i:s', intdiv(time(), self::WINDOW) * self::WINDOW);
            $sql = match ($this->pdo->getAttribute(\PDO::ATTR_DRIVER_NAME)) {
                'mysql' => 'INSERT INTO admin_throttle (bucket, window_start, attempts) VALUES (:b, :w, 1)
                    ON DUPLICATE KEY UPDATE attempts = IF(VALUES(window_start) > window_start, 1, attempts + 1),
                        window_start = GREATEST(window_start, VALUES(window_start))',
                'sqlite' => 'INSERT INTO admin_throttle (bucket, window_start, attempts) VALUES (:b, :w, 1)
                    ON CONFLICT (bucket) DO UPDATE SET
                        attempts = CASE WHEN excluded.window_start > window_start THEN 1 ELSE attempts + 1 END,
                        window_start = MAX(window_start, excluded.window_start)',
                default => throw new \LogicException('Unsupported database driver.'),
            };
            $this->pdo->prepare($sql)->execute(['b' => $bucket, 'w' => $window]);
        }
    }

    public function reset(string $ip): void
    {
        $this->pdo->prepare('DELETE FROM admin_throttle WHERE bucket = :b')->execute(['b' => $this->ipBucket($ip)]);
    }

    private function count(string $bucket): int
    {
        $select = $this->pdo->prepare('SELECT window_start, attempts FROM admin_throttle WHERE bucket = :b');
        $select->execute(['b' => $bucket]);
        $row = $select->fetch(\PDO::FETCH_ASSOC);
        $current = gmdate('Y-m-d H:i:s', intdiv(time(), self::WINDOW) * self::WINDOW);

        return is_array($row) && $row['window_start'] === $current ? (int) $row['attempts'] : 0;
    }

    private function ipBucket(string $ip): string
    {
        return 'ip:' . substr(hash('sha256', $ip), 0, 40);
    }
}
