<?php

declare(strict_types=1);

namespace VamsysMcp\Install;

use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Client\ClientInterface;
use VamsysMcp\AirlineConfig;
use VamsysMcp\Config;
use VamsysMcp\Exception\ConfigException;
use VamsysMcp\Exception\VamsysMcpException;
use VamsysMcp\OAuth\McpUsers;
use VamsysMcp\Paths;
use VamsysMcp\RateLimiter;
use VamsysMcp\SystemClock;
use VamsysMcp\TokenStore;

/**
 * Turns installer input (web form or CLI prompts) into a config file and a
 * ready database. Shared by public/setup.php and bin/install.php.
 *
 * Re-running it on an existing installation keeps every secret the operator
 * leaves blank, and every setting it does not know about.
 */
final class InstallService
{
    public const string DEFAULT_REPOSITORY = 'DarrianCZE/vAMSYS-Unofficial-MCP';
    public const int MIN_ADMIN_PASSWORD_LENGTH = 12;

    public function __construct(
        private readonly Paths $paths,
        private readonly ClientInterface $http,
    ) {
    }

    /**
     * Validate input and merge it into the existing raw config.
     *
     * Input keys: db{host,port,name,user,password}, airlines[]{key,name,client_id,
     * client_secret,weight_unit,rate_cap,pilots_api,delete}, allowed_hosts,
     * audit_log, admin_password, admin_password_confirm.
     *
     * @param array<string, mixed> $input
     * @param array<string, mixed> $existing current raw config ([] on first install)
     *
     * @return array{0: array<string, mixed>, 1: list<string>} raw config and errors
     */
    public function buildConfig(array $input, array $existing): array
    {
        $errors = [];
        $raw = $existing;

        // Database
        $db = (array) ($input['db'] ?? []);
        $current = self::parseDsn((string) ($existing['database']['dsn'] ?? ''));
        $host = trim((string) ($db['host'] ?? $current['host'] ?? 'localhost'));
        $port = (int) ($db['port'] ?? $current['port'] ?? 3306);
        $name = trim((string) ($db['name'] ?? $current['dbname'] ?? ''));
        $user = trim((string) ($db['user'] ?? $existing['database']['username'] ?? ''));
        $password = (string) ($db['password'] ?? '');
        if ($password === '') {
            $password = (string) ($existing['database']['password'] ?? '');
        }
        if (preg_match('/^[a-z0-9.-]+$/i', $host) !== 1 || $name === '' || $user === '' || $password === '' || $port < 1) {
            $errors[] = 'Database host, port, name, user and password are required.';
        }
        if (preg_match('/^[A-Za-z0-9_$-]+$/', $name) !== 1) {
            $errors[] = 'Database name may contain only letters, digits, _, $ and -.';
        }
        $raw['database'] = [
            'dsn' => sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $host, $port, $name),
            'username' => $user,
            'password' => $password,
        ];

        // Airlines
        $existingAirlines = (array) ($existing['airlines'] ?? []);
        $airlines = [];
        foreach ((array) ($input['airlines'] ?? []) as $a) {
            $a = (array) $a;
            $key = strtolower(trim((string) ($a['key'] ?? '')));
            if ($key === '' || !empty($a['delete'])) {
                continue;
            }
            $old = (array) ($existingAirlines[$key] ?? []);
            $secret = (string) ($a['client_secret'] ?? '');
            $entry = [
                'name' => trim((string) ($a['name'] ?? '')) ?: $key,
                'client_id' => trim((string) ($a['client_id'] ?? '')),
                'client_secret' => $secret !== '' ? $secret : (string) ($old['client_secret'] ?? ''),
                'weight_unit' => (string) ($a['weight_unit'] ?? ''),
                'rate_cap' => (int) ($a['rate_cap'] ?? AirlineConfig::DEFAULT_RATE_CAP),
                'pilots_api' => (bool) ($a['pilots_api'] ?? false),
            ];
            if (isset($airlines[$key])) {
                $errors[] = "Airline key '{$key}' is used twice.";
                continue;
            }
            try {
                AirlineConfig::fromArray($key, $entry);
            } catch (ConfigException $e) {
                $errors[] = $e->getMessage();
            }
            $airlines[$key] = $entry;
        }
        if ($airlines === []) {
            $errors[] = 'Configure at least one airline.';
        }
        $raw['airlines'] = $airlines;

        // HTTP endpoint
        $hosts = array_values(array_filter(preg_split('/[\s,]+/', strtolower((string) ($input['allowed_hosts'] ?? ''))) ?: []));
        foreach ($hosts as $h) {
            if (preg_match('/^(?:[a-z0-9-]+\.)*[a-z0-9-]+$|^\[[0-9a-f:]+\]$/', $h) !== 1) {
                $errors[] = "'{$h}' is not a valid host name (no scheme, port or path).";
            }
        }
        if ($hosts === []) {
            $errors[] = 'Enter at least one host name for the MCP endpoint, e.g. mcp.your-va.com.';
        }
        $raw['http'] = (array) ($existing['http'] ?? []);
        $raw['http']['allowed_hosts'] = $hosts;
        unset($raw['http']['bearer_token_sha256']); // static tokens were replaced by OAuth in 0.2.0

        if (array_key_exists('audit_log', $input)) {
            $raw['audit_log'] = (bool) $input['audit_log'];
        }

        // Admin password (web admin UI)
        $newPassword = (string) ($input['admin_password'] ?? '');
        if ($newPassword !== '') {
            if (strlen($newPassword) < self::MIN_ADMIN_PASSWORD_LENGTH) {
                $errors[] = 'The admin password must have at least ' . self::MIN_ADMIN_PASSWORD_LENGTH . ' characters.';
            } elseif ($newPassword !== (string) ($input['admin_password_confirm'] ?? '')) {
                $errors[] = 'The admin passwords do not match.';
            } else {
                $raw['admin']['password_hash'] = password_hash($newPassword, PASSWORD_DEFAULT);
            }
        } elseif (empty($existing['admin']['password_hash'])) {
            $errors[] = 'Set an admin password for the web admin.';
        }
        $raw['admin']['web_enabled'] ??= true;

        $raw['app_key'] ??= SecretBox::generateKey();
        $raw['update']['repository'] ??= self::DEFAULT_REPOSITORY;

        if ($errors === []) {
            try {
                Config::fromArray($raw);
            } catch (ConfigException $e) {
                $errors[] = $e->getMessage();
            }
        }

        return [$raw, $errors];
    }

    /**
     * Connect, create/upgrade tables, optionally verify the vAMSYS credentials,
     * then write the config. Nothing is written if any step fails.
     *
     * @param array<string, mixed> $raw    from buildConfig()
     * @param array{user: string, password: string}|null $ddl       DB login allowed to CREATE/ALTER; null = use the app user
     * @param array{username: string, password: string}|null $mcpUser first MCP user (who may connect Claude) to create
     *
     * @return array{migrated: list<string>, mcp_user: string|null}
     */
    public function install(array $raw, ?array $ddl, bool $verifyAirlines, ?FtpCredentials $ftp, #[\SensitiveParameter] ?array $mcpUser = null): array
    {
        $config = Config::fromArray($raw);
        $pdo = self::connect($config->dbDsn, (string) $config->dbUser, (string) ($raw['database']['password'] ?? ''), 'the application user');

        $migrator = new Migrator($this->paths->migrationsDir());
        $migrated = [];
        if ($migrator->pending($pdo) !== []) {
            $ddlPdo = $ddl !== null && $ddl['user'] !== ''
                ? self::connect($config->dbDsn, $ddl['user'], $ddl['password'], 'the DB admin user')
                : $pdo;
            try {
                $migrated = $migrator->migrate($ddlPdo);
            } catch (\PDOException $e) {
                throw new InstallException(
                    'Creating the database tables failed (' . $e->getMessage() . '). Enter a DB user that may CREATE and ALTER tables.',
                );
            }
        }

        if ($verifyAirlines) {
            $psr17 = new Psr17Factory();
            $clock = new SystemClock();
            $tokens = new TokenStore($pdo, $this->http, $psr17, $psr17, new RateLimiter($pdo, $clock, $config->maxRateWaitSeconds), $clock, $config->tokenUrl, $config->tokenRefreshMarginSeconds);
            foreach ($config->airlines as $airline) {
                try {
                    $tokens->invalidate($airline->key);
                    $tokens->getToken($airline);
                } catch (VamsysMcpException $e) {
                    throw new InstallException($e->getMessage());
                }
            }
        }

        $createdUser = null;
        if ($mcpUser !== null && trim($mcpUser['username']) !== '') {
            try {
                (new McpUsers($pdo))->create($mcpUser['username'], $mcpUser['password']);
                $createdUser = strtolower(trim($mcpUser['username']));
            } catch (ConfigException $e) {
                throw new InstallException('MCP user: ' . $e->getMessage());
            }
        }

        if ($ftp !== null) {
            (new EncryptedSettings($pdo, SecretBox::fromBase64((string) $raw['app_key'])))->put(EncryptedSettings::FTP, $ftp->toArray());
        }

        (new ConfigFile($this->paths->configFile()))->write($raw);

        return ['migrated' => $migrated, 'mcp_user' => $createdUser];
    }

    public static function connect(string $dsn, string $user, #[\SensitiveParameter] string $password, string $who): \PDO
    {
        try {
            return new \PDO($dsn, $user, $password, [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
                \PDO::ATTR_EMULATE_PREPARES => false,
                \PDO::ATTR_TIMEOUT => 5,
            ]);
        } catch (\PDOException $e) {
            // PDO messages contain user@host but never the password.
            throw new InstallException("Could not connect to the database as {$who}: " . $e->getMessage());
        }
    }

    /**
     * @return array<string, string>
     */
    public static function parseDsn(string $dsn): array
    {
        $out = [];
        $body = substr($dsn, (int) strpos($dsn, ':') + 1);
        foreach (explode(';', $body) as $part) {
            if (str_contains($part, '=')) {
                [$k, $v] = explode('=', $part, 2);
                $out[trim($k)] = trim($v);
            }
        }

        return $out;
    }
}
