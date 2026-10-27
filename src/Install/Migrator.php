<?php

declare(strict_types=1);

namespace VamsysMcp\Install;

/**
 * Applies migrations/NNNN_name.sql in order and records them in
 * schema_migration.
 *
 * Reading the state works with the least-privilege app user; applying needs a
 * DB user with CREATE/ALTER, which is asked for at install/update time and
 * never stored. Migrations must be additive and idempotent (IF NOT EXISTS) so
 * the previous code version keeps working on the new schema and a manually
 * imported schema.sql is harmless.
 */
final class Migrator
{
    public function __construct(private readonly string $dir)
    {
    }

    /**
     * @return array<string, string> version => file path, in order
     */
    public function available(): array
    {
        $files = glob($this->dir . '/*.sql') ?: [];
        $versions = [];
        foreach ($files as $file) {
            if (preg_match('/^(\d{4})_[a-z0-9_]+\.sql$/', basename($file), $m) === 1) {
                $versions[$m[1]] = $file;
            }
        }
        ksort($versions, SORT_STRING);

        return $versions;
    }

    /**
     * @return list<string>
     */
    public function applied(\PDO $pdo): array
    {
        try {
            return array_map('strval', $pdo->query('SELECT version FROM schema_migration ORDER BY version')->fetchAll(\PDO::FETCH_COLUMN));
        } catch (\PDOException $e) {
            // 42S02 = MariaDB/MySQL "table doesn't exist"; SQLite reports HY000 "no such table".
            if ($e->getCode() === '42S02' || str_contains($e->getMessage(), 'no such table')) {
                return [];
            }
            throw $e;
        }
    }

    /**
     * @return list<string>
     */
    public function pending(\PDO $pdo): array
    {
        return array_values(array_diff(array_keys($this->available()), $this->applied($pdo)));
    }

    /**
     * Apply every pending migration. MariaDB commits DDL implicitly, so each
     * migration is recorded right after it succeeds.
     *
     * @return list<string> applied versions
     */
    public function migrate(\PDO $ddl): array
    {
        $ddl->exec('CREATE TABLE IF NOT EXISTS schema_migration (version VARCHAR(16) NOT NULL PRIMARY KEY, applied_at DATETIME NOT NULL)');
        $done = [];
        $files = $this->available();
        foreach ($this->pending($ddl) as $version) {
            foreach (self::statements((string) file_get_contents($files[$version])) as $statement) {
                $ddl->exec($statement);
            }
            // Tolerate a record added meanwhile (e.g. the operator ran the migration by hand).
            $ignore = $ddl->getAttribute(\PDO::ATTR_DRIVER_NAME) === 'sqlite' ? 'INSERT OR IGNORE' : 'INSERT IGNORE';
            $ddl->prepare("{$ignore} INTO schema_migration (version, applied_at) VALUES (:v, :t)")
                ->execute(['v' => $version, 't' => gmdate('Y-m-d H:i:s')]);
            $done[] = $version;
        }

        return $done;
    }

    /**
     * @return list<string>
     */
    public static function statements(string $sql): array
    {
        $lines = array_filter(explode("\n", str_replace("\r", '', $sql)), static fn (string $l): bool => !str_starts_with(ltrim($l), '--'));
        $parts = preg_split('/;\s*(?:\n|$)/', implode("\n", $lines)) ?: [];

        return array_values(array_filter(array_map('trim', $parts), static fn (string $s): bool => $s !== ''));
    }
}
