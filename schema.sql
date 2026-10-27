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

-- migrations/0003_oauth.sql
-- OAuth 2.1 authorization server (v0.2.0): people allowed to connect MCP clients,
-- registered clients, authorization codes and tokens. Codes and tokens are stored
-- only as SHA-256 hashes; passwords only as password_hash().

CREATE TABLE IF NOT EXISTS mcp_user (
    id             INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    username       VARCHAR(64)   NOT NULL,
    password_hash  VARCHAR(255)  NOT NULL,
    disabled       TINYINT(1)    NOT NULL DEFAULT 0,
    created_at     DATETIME      NOT NULL,
    last_login_at  DATETIME      NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_mcp_user_username (username)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

CREATE TABLE IF NOT EXISTS mcp_oauth_client (
    client_id      VARCHAR(255)  NOT NULL,
    kind           VARCHAR(8)    NOT NULL,
    client_name    VARCHAR(255)  NOT NULL,
    redirect_uris  TEXT          NOT NULL,
    created_at     DATETIME      NOT NULL,
    fetched_at     DATETIME      NULL,
    PRIMARY KEY (client_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

CREATE TABLE IF NOT EXISTS mcp_oauth_code (
    code_hash      CHAR(64)      NOT NULL,
    client_id      VARCHAR(255)  NOT NULL,
    user_id        INT UNSIGNED  NOT NULL,
    redirect_uri   TEXT          NOT NULL,
    code_challenge VARCHAR(128)  NOT NULL,
    resource       VARCHAR(255)  NOT NULL,
    scope          VARCHAR(255)  NOT NULL,
    grant_id       CHAR(32)      NOT NULL,
    expires_at     DATETIME      NOT NULL,
    used           TINYINT(1)    NOT NULL DEFAULT 0,
    PRIMARY KEY (code_hash)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

CREATE TABLE IF NOT EXISTS mcp_oauth_token (
    token_hash     CHAR(64)      NOT NULL,
    kind           VARCHAR(8)    NOT NULL,
    grant_id       CHAR(32)      NOT NULL,
    client_id      VARCHAR(255)  NOT NULL,
    user_id        INT UNSIGNED  NOT NULL,
    resource       VARCHAR(255)  NOT NULL,
    scope          VARCHAR(255)  NOT NULL,
    created_at     DATETIME      NOT NULL,
    expires_at     DATETIME      NOT NULL,
    revoked        TINYINT(1)    NOT NULL DEFAULT 0,
    PRIMARY KEY (token_hash),
    KEY idx_mcp_oauth_token_grant (grant_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

-- migrations/0004_caches.sql
-- v0.3.0: caches for airport codes and route sets (non-secret vAMSYS data).

-- Airport ID -> ICAO/IATA/name per airline, refreshed after vamsys.airport_cache_ttl
-- or when a route references an unknown airport ID.
CREATE TABLE IF NOT EXISTS airport_cache (
    airline      VARCHAR(32)   NOT NULL,
    airport_id   INT UNSIGNED  NOT NULL,
    icao         VARCHAR(8)    NULL,
    iata         VARCHAR(8)    NULL,
    name         VARCHAR(255)  NOT NULL,
    fetched_at   DATETIME      NOT NULL,
    PRIMARY KEY (airline, airport_id),
    KEY idx_airport_cache_icao (airline, icao),
    KEY idx_airport_cache_iata (airline, iata)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

-- Compact projection of an airline's full route set (JSON), used by count_routes
-- and find_routes for vamsys.route_cache_ttl. An unfinished scan keeps its cursor
-- so the next call can continue it.
CREATE TABLE IF NOT EXISTS route_cache (
    airline      VARCHAR(32)   NOT NULL,
    started_at   DATETIME      NOT NULL,
    fetched_at   DATETIME      NULL,
    complete     TINYINT(1)    NOT NULL DEFAULT 0,
    next_cursor  VARCHAR(512)  NULL,
    route_count  INT UNSIGNED  NOT NULL DEFAULT 0,
    data         MEDIUMTEXT    NOT NULL,
    PRIMARY KEY (airline)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

-- Least-privilege application user (run as an admin, adjust host/password):
-- CREATE USER 'vamsys_mcp'@'localhost' IDENTIFIED BY 'a-long-random-password';
-- GRANT SELECT, INSERT, UPDATE, DELETE ON vamsys_mcp.* TO 'vamsys_mcp'@'localhost';
