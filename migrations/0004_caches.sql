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
