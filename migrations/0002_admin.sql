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
