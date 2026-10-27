<?php

declare(strict_types=1);

namespace VamsysMcp\Cache;

/**
 * Named lock so two calls never scan the same route set at the same time:
 * the second waits for the first, then continues from its saved progress.
 *
 * MariaDB/MySQL: GET_LOCK, held by the DB connection (released on
 * disconnect, so a killed PHP process never leaves it held). SQLite (tests
 * only, one process): an in-process registry with no waiting.
 */
final class ScanLock
{
    /** @var array<string, true> SQLite: locks held in this process */
    private static array $held = [];

    public function __construct(private readonly \PDO $pdo)
    {
    }

    /**
     * @param float $timeoutSeconds how long to wait for a lock held by another call
     */
    public function acquire(string $name, float $timeoutSeconds): bool
    {
        $name = self::name($name);
        if ($this->pdo->getAttribute(\PDO::ATTR_DRIVER_NAME) !== 'mysql') {
            if (isset(self::$held[$name])) {
                return false;
            }
            self::$held[$name] = true;

            return true;
        }
        $stmt = $this->pdo->prepare('SELECT GET_LOCK(:n, :t)');
        $stmt->execute(['n' => $name, 't' => max(0, (int) floor($timeoutSeconds))]);

        return (int) $stmt->fetchColumn() === 1;
    }

    public function release(string $name): void
    {
        $name = self::name($name);
        if ($this->pdo->getAttribute(\PDO::ATTR_DRIVER_NAME) !== 'mysql') {
            unset(self::$held[$name]);

            return;
        }
        $this->pdo->prepare('SELECT RELEASE_LOCK(:n)')->execute(['n' => $name]);
    }

    /** Lock names are server-wide and at most 64 characters. */
    private static function name(string $name): string
    {
        return 'vamsys_mcp_' . md5($name);
    }
}
