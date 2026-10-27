<?php

declare(strict_types=1);

namespace VamsysMcp;

use VamsysMcp\Exception\ConfigException;
use VamsysMcp\Exception\UnknownAirlineException;
use VamsysMcp\OAuth\RedirectPolicy;

/**
 * Server configuration, loaded from a PHP file that lives outside the web root.
 * See config/config.example.php for every option.
 */
final class Config
{
    /**
     * @param array<string, AirlineConfig> $airlines
     * @param list<string>                 $httpAllowedHosts
     * @param list<string>                 $oauthRedirectHosts    hosts OAuth clients may redirect to after sign-in
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
        public readonly ?string $sessionDir = null,
        public readonly ?string $publicUrl = null,
        public readonly array $oauthRedirectHosts = RedirectPolicy::DEFAULT_HOSTS,
        public readonly int $oauthAccessTokenTtl = 3600,
        public readonly int $oauthRefreshTokenTtl = 30 * 86400,
    ) {
        if ($airlines === []) {
            throw new ConfigException('No airlines configured.');
        }
        if ($pageSize < 1 || $maxPagesPerCall < 1 || $maxRateWaitSeconds < 0) {
            throw new ConfigException('page_size and max_pages_per_call must be >= 1, max_rate_wait_seconds >= 0.');
        }
        if ($oauthAccessTokenTtl < 60 || $oauthRefreshTokenTtl < $oauthAccessTokenTtl) {
            throw new ConfigException('oauth.access_token_ttl must be >= 60 and refresh_token_ttl >= access_token_ttl.');
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
        $oauth = (array) ($raw['oauth'] ?? []);

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
            sessionDir: isset($http['session_dir']) ? (string) $http['session_dir'] : null,
            publicUrl: isset($http['public_url']) ? rtrim((string) $http['public_url'], '/') : null,
            oauthRedirectHosts: array_values(array_map('strval', (array) ($oauth['allowed_redirect_hosts'] ?? RedirectPolicy::DEFAULT_HOSTS))),
            oauthAccessTokenTtl: (int) ($oauth['access_token_ttl'] ?? 3600),
            oauthRefreshTokenTtl: (int) ($oauth['refresh_token_ttl'] ?? 30 * 86400),
        );
    }

    /**
     * Public base URL of this installation without trailing slash: http.public_url,
     * else https://<first allowed host>. It is the OAuth issuer and the base of
     * the MCP resource URLs.
     */
    public function baseUrl(): string
    {
        if ($this->publicUrl !== null && $this->publicUrl !== '') {
            return $this->publicUrl;
        }
        if ($this->httpAllowedHosts === []) {
            throw new ConfigException('Set http.allowed_hosts (or http.public_url) to the host name of the MCP endpoint.');
        }

        return 'https://' . strtolower($this->httpAllowedHosts[0]);
    }

    /**
     * MCP resource URLs (RFC 8707), normalized without trailing slash: /mcp (recommended) and the site root.
     *
     * @return list<string>
     */
    public function resourceUrls(): array
    {
        return [$this->baseUrl() . '/mcp', $this->baseUrl()];
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
