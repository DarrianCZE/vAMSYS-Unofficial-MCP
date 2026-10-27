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
        $pdo->exec('CREATE TABLE mcp_user (id INTEGER PRIMARY KEY AUTOINCREMENT, username TEXT NOT NULL UNIQUE, password_hash TEXT NOT NULL, disabled INTEGER NOT NULL DEFAULT 0, created_at TEXT NOT NULL, last_login_at TEXT)');
        $pdo->exec('CREATE TABLE mcp_oauth_client (client_id TEXT PRIMARY KEY, kind TEXT NOT NULL, client_name TEXT NOT NULL, redirect_uris TEXT NOT NULL, created_at TEXT NOT NULL, fetched_at TEXT)');
        $pdo->exec('CREATE TABLE mcp_oauth_code (code_hash TEXT PRIMARY KEY, client_id TEXT NOT NULL, user_id INTEGER NOT NULL, redirect_uri TEXT NOT NULL, code_challenge TEXT NOT NULL, resource TEXT NOT NULL, scope TEXT NOT NULL, grant_id TEXT NOT NULL, expires_at TEXT NOT NULL, used INTEGER NOT NULL DEFAULT 0)');
        $pdo->exec('CREATE TABLE mcp_oauth_token (token_hash TEXT PRIMARY KEY, kind TEXT NOT NULL, grant_id TEXT NOT NULL, client_id TEXT NOT NULL, user_id INTEGER NOT NULL, resource TEXT NOT NULL, scope TEXT NOT NULL, created_at TEXT NOT NULL, expires_at TEXT NOT NULL, revoked INTEGER NOT NULL DEFAULT 0)');
        $pdo->exec('CREATE TABLE airport_cache (airline TEXT NOT NULL, airport_id INTEGER NOT NULL, icao TEXT, iata TEXT, name TEXT NOT NULL, fetched_at TEXT NOT NULL, PRIMARY KEY (airline, airport_id))');
        $pdo->exec('CREATE TABLE route_cache (airline TEXT PRIMARY KEY, started_at TEXT NOT NULL, fetched_at TEXT, complete INTEGER NOT NULL DEFAULT 0, next_cursor TEXT, route_count INTEGER NOT NULL DEFAULT 0, data TEXT NOT NULL)');
        $pdo->exec('CREATE TABLE route_scan (airline TEXT NOT NULL, query_key TEXT NOT NULL, fetched_at TEXT, route_count INTEGER, data TEXT, scan_started_at TEXT, scan_cursor TEXT, scan_data TEXT, PRIMARY KEY (airline, query_key))');

        return $pdo;
    }
}
