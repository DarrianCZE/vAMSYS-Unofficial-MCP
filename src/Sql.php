<?php

declare(strict_types=1);

namespace VamsysMcp;

/** Driver-specific SQL helpers (MariaDB/MySQL in production, SQLite in tests). */
final class Sql
{
    /**
     * Insert a row or update it when the primary key exists.
     *
     * @param string|list<string>       $key primary key column(s)
     * @param array<string, scalar|null> $row column => value, including the key column(s)
     */
    public static function upsert(\PDO $pdo, string $table, string|array $key, array $row): void
    {
        $keys = (array) $key;
        $columns = array_keys($row);
        $placeholders = implode(', ', array_map(static fn (string $c): string => ':' . $c, $columns));
        $updates = array_filter($columns, static fn (string $c): bool => !in_array($c, $keys, true));
        $sql = sprintf('INSERT INTO %s (%s) VALUES (%s) ', $table, implode(', ', $columns), $placeholders);
        $sql .= match ($pdo->getAttribute(\PDO::ATTR_DRIVER_NAME)) {
            'mysql' => 'ON DUPLICATE KEY UPDATE ' . implode(', ', array_map(static fn (string $c): string => "{$c} = VALUES({$c})", $updates)),
            'sqlite' => 'ON CONFLICT (' . implode(', ', $keys) . ') DO UPDATE SET ' . implode(', ', array_map(static fn (string $c): string => "{$c} = excluded.{$c}", $updates)),
            default => throw new \LogicException('Unsupported database driver.'),
        };
        $pdo->prepare($sql)->execute($row);
    }

    public static function now(?\DateTimeImmutable $at = null): string
    {
        return ($at ?? new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    /** Whether a UTC DATETIME string is older than $seconds before $now. */
    public static function olderThan(?string $datetime, int $seconds, \DateTimeImmutable $now): bool
    {
        return $datetime === null || strtotime($datetime . ' UTC') < $now->getTimestamp() - $seconds;
    }
}
