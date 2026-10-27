-- v0.4.1: route sets per query: the airline's full set (query_key '') and the
-- subsets vAMSYS returns for server-side filters (query_key = SHA-1 of the query).
-- The last complete set (data, fetched_at) stays usable while a new scan
-- (scan_*) runs and is replaced only when that scan completes.
-- Replaces route_cache, which is no longer used (kept: migrations never drop).
CREATE TABLE IF NOT EXISTS route_scan (
    airline          VARCHAR(32)   NOT NULL,
    query_key        VARCHAR(64)   NOT NULL,
    fetched_at       DATETIME      NULL,
    route_count      INT UNSIGNED  NULL,
    data             MEDIUMTEXT    NULL,
    scan_started_at  DATETIME      NULL,
    scan_cursor      VARCHAR(512)  NULL,
    scan_data        MEDIUMTEXT    NULL,
    PRIMARY KEY (airline, query_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;
