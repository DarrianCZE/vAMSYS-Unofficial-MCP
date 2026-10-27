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
