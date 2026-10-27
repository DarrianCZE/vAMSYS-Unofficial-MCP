-- vAMSYS Unofficial MCP server: full database schema (MariaDB 11; also MySQL 8).
-- The installer (setup.php / bin/install.php) creates these tables for you from
-- migrations/; use this file only for a manual setup. It is safe to import it
-- and run the installer/updater afterwards (all statements use IF NOT EXISTS).
-- Generated from migrations/*.sql; do not edit by hand.

CREATE TABLE IF NOT EXISTS schema_migration (version VARCHAR(16) NOT NULL PRIMARY KEY, applied_at DATETIME NOT NULL);

-- migrations/0001_initial.sql
-- Cached OAuth tokens, rate-limit windows and the optional call audit log.
-- All DATETIME values are UTC. Client secrets are never stored in the database.

CREATE TABLE IF NOT EXISTS oauth_token (
    airline      VARCHAR(32)   NOT NULL,
    access_token TEXT          NOT NULL,
    expires_at   DATETIME      NOT NULL,
    PRIMARY KEY (airline)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

CREATE TABLE IF NOT EXISTS rate_window (
    airline      VARCHAR(32)   NOT NULL,
    window_start DATETIME      NOT NULL,
    req_count    INT UNSIGNED  NOT NULL,
    PRIMARY KEY (airline)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

CREATE TABLE IF NOT EXISTS call_log (
    id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    airline      VARCHAR(32)   NOT NULL,
    method       VARCHAR(8)    NOT NULL,
    path         VARCHAR(255)  NOT NULL,
    status       SMALLINT UNSIGNED NOT NULL,
    duration_ms  INT UNSIGNED  NOT NULL,
    created_at   DATETIME      NOT NULL,
    PRIMARY KEY (id),
    KEY idx_call_log_airline_created (airline, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

-- migrations/0002_admin.sql
-- Secrets that must live in the DB (FTP credentials for the updater), encrypted
-- with libsodium secretbox. The key is app_key in config.php, never in the DB.
CREATE TABLE IF NOT EXISTS encrypted_setting (
    name         VARCHAR(64)   NOT NULL,
    ciphertext   TEXT          NOT NULL,
    updated_at   DATETIME      NOT NULL,
    PRIMARY KEY (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

-- Fixed-window counters for failed admin logins (per hashed client IP and global).
CREATE TABLE IF NOT EXISTS admin_throttle (
    bucket       VARCHAR(64)   NOT NULL,
    window_start DATETIME      NOT NULL,
    attempts     INT UNSIGNED  NOT NULL,
    PRIMARY KEY (bucket)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

-- Least-privilege application user (run as an admin, adjust host/password):
-- CREATE USER 'vamsys_mcp'@'localhost' IDENTIFIED BY 'a-long-random-password';
-- GRANT SELECT, INSERT, UPDATE, DELETE ON vamsys_mcp.* TO 'vamsys_mcp'@'localhost';
