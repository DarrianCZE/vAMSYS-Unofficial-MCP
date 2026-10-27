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
