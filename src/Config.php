<?php

declare(strict_types=1);

namespace VamsysMcp;

use VamsysMcp\Exception\ConfigException;
use VamsysMcp\Exception\UnknownAirlineException;

/**
 * Server configuration, loaded from a PHP file that lives outside the web root.
 * See config/config.example.php for every option.
 */
final class Config
{
    /**
     * @param array<string, AirlineConfig> $airlines
     * @param list<string>                 $httpAllowedHosts
     * @param list<string>                 $httpBearerTokenHashes SHA-256 hex digests of accepted bearer tokens
     */
    public function __construct(
        public readonly array $airlines,
        public readonly string $dbDsn,
        public readonly ?string $dbUser = null,
        #[\SensitiveParameter] private readonly ?string $dbPassword = null,
        public readonly string $apiBaseUrl = 'https://vamsys.io/api/v3/operations',
        public readonly string $tokenUrl = 'https://vamsys.io/oauth/token',
        public readonly int $pageSize = 100,
        public readonly int $maxPagesPerCall = 10,
        public readonly int $maxRateWaitSeconds = 10,
        public readonly int $tokenRefreshMarginSeconds = 300,
        public readonly int $httpTimeoutSeconds = 20,
        public readonly bool $auditLog = false,
        public readonly array $httpAllowedHosts = [],
        public readonly array $httpBearerTokenHashes = [],
        public readonly ?string $sessionDir = null,
    ) {
        if ($airlines === []) {
            throw new ConfigException('No airlines configured.');
        }
        if ($pageSize < 1 || $maxPagesPerCall < 1 || $maxRateWaitSeconds < 0) {
            throw new ConfigException('page_size and max_pages_per_call must be >= 1, max_rate_wait_seconds >= 0.');
        }
        foreach ($httpBearerTokenHashes as $hash) {
            if (preg_match('/^[0-9a-f]{64}$/', $hash) !== 1) {
                throw new ConfigException('http.bearer_token_sha256 entries must be lowercase SHA-256 hex digests.');
            }
        }
    }

    public static function fromFile(string $path): self
    {
        if (!is_file($path) || !is_readable($path)) {
            throw new ConfigException("Config file not found or not readable: {$path}");
        }
        $raw = require $path;
        if (!is_array($raw)) {
            throw new ConfigException('Config file must return an array.');
        }

        return self::fromArray($raw);
    }

    /**
     * @param array<string, mixed> $raw
     */
    public static function fromArray(array $raw): self
    {
        $airlines = [];
        foreach ((array) ($raw['airlines'] ?? []) as $key => $airline) {
            $airlines[(string) $key] = AirlineConfig::fromArray((string) $key, (array) $airline);
        }
        $db = (array) ($raw['database'] ?? []);
        $vamsys = (array) ($raw['vamsys'] ?? []);
        $http = (array) ($raw['http'] ?? []);

        return new self(
            airlines: $airlines,
            dbDsn: (string) ($db['dsn'] ?? throw new ConfigException('database.dsn is required.')),
            dbUser: isset($db['username']) ? (string) $db['username'] : null,
            dbPassword: isset($db['password']) ? (string) $db['password'] : null,
            apiBaseUrl: rtrim((string) ($vamsys['api_base_url'] ?? 'https://vamsys.io/api/v3/operations'), '/'),
            tokenUrl: (string) ($vamsys['token_url'] ?? 'https://vamsys.io/oauth/token'),
            pageSize: (int) ($vamsys['page_size'] ?? 100),
            maxPagesPerCall: (int) ($vamsys['max_pages_per_call'] ?? 10),
            maxRateWaitSeconds: (int) ($vamsys['max_rate_wait_seconds'] ?? 10),
            tokenRefreshMarginSeconds: (int) ($vamsys['token_refresh_margin_seconds'] ?? 300),
            httpTimeoutSeconds: (int) ($vamsys['http_timeout_seconds'] ?? 20),
            auditLog: (bool) ($raw['audit_log'] ?? false),
            httpAllowedHosts: array_values(array_map('strval', (array) ($http['allowed_hosts'] ?? []))),
            httpBearerTokenHashes: array_values(array_map(
                static fn ($h): string => strtolower((string) $h),
                (array) ($http['bearer_token_sha256'] ?? []),
            )),
            sessionDir: isset($http['session_dir']) ? (string) $http['session_dir'] : null,
        );
    }

    public function airline(string $key): AirlineConfig
    {
        return $this->airlines[$key] ?? throw new UnknownAirlineException(
            "Unknown airline '{$key}'. Configured airlines: " . implode(', ', array_keys($this->airlines)) . '.',
        );
    }

    public function createPdo(): \PDO
    {
        return new \PDO($this->dbDsn, $this->dbUser, $this->dbPassword, [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
            \PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        $info = get_object_vars($this);
        $info['dbPassword'] = '***';

        return $info;
    }
}
