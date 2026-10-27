<?php

declare(strict_types=1);

namespace VamsysMcp\Tests\Support;

/**
 * In-memory SQLite with the same tables as schema.sql. Production runs on
 * MariaDB; the SQL used there (ON DUPLICATE KEY UPDATE) is selected by driver.
 */
final class TestDatabase
{
    public static function create(string $dsn = 'sqlite::memory:'): \PDO
    {
        $pdo = new \PDO($dsn, null, null, [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
        ]);
        $pdo->exec('CREATE TABLE oauth_token (airline TEXT PRIMARY KEY, access_token TEXT NOT NULL, expires_at TEXT NOT NULL)');
        $pdo->exec('CREATE TABLE rate_window (airline TEXT PRIMARY KEY, window_start TEXT NOT NULL, req_count INTEGER NOT NULL)');
        $pdo->exec('CREATE TABLE call_log (id INTEGER PRIMARY KEY AUTOINCREMENT, airline TEXT, method TEXT, path TEXT, status INTEGER, duration_ms INTEGER, created_at TEXT)');
        $pdo->exec('CREATE TABLE encrypted_setting (name TEXT PRIMARY KEY, ciphertext TEXT NOT NULL, updated_at TEXT NOT NULL)');
        $pdo->exec('CREATE TABLE admin_throttle (bucket TEXT PRIMARY KEY, window_start TEXT NOT NULL, attempts INTEGER NOT NULL)');

        return $pdo;
    }
}
