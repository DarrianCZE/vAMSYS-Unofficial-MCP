<?php

declare(strict_types=1);

namespace VamsysMcp;

use Mcp\Capability\Registry\Container;
use Mcp\Schema\ToolAnnotations;
use Mcp\Server;
use Mcp\Server\Builder;
use Nyholm\Psr7\Factory\Psr17Factory;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\HttpClient\Psr18Client;
use VamsysMcp\Cache\AirportCache;
use VamsysMcp\Cache\RouteCache;
use VamsysMcp\Tools\CountRoutesTool;
use VamsysMcp\Tools\FindRoutesTool;
use VamsysMcp\Tools\ListAirlinesTool;
use VamsysMcp\Tools\RouteQuery;

/**
 * Wires config, database, vAMSYS client and tools into an MCP server builder.
 * Shared by bin/server-stdio.php and public/index.php.
 */
final class App
{
    public const string NAME = 'vamsys-unofficial-mcp';
    public const string VERSION = '0.3.0';
    /** Oldest installed version that may auto-update to this one (raise it when an update cannot be applied in place). */
    public const string UPDATE_FROM_MIN = '0.1.0';
    public const string REQUIRES_PHP = '8.4.0';

    private function __construct(
        public readonly Config $config,
        public readonly \PDO $pdo,
        private readonly VamsysClient $client,
    ) {
    }

    /** OAuth authorization server for the HTTP endpoint (see src/OAuth). */
    public static function oauth(Config $config, \PDO $pdo): OAuth\OAuthService
    {
        $clock = new SystemClock();
        $users = new OAuth\McpUsers($pdo);
        $clients = new OAuth\ClientRegistry(
            $pdo,
            new OAuth\ClientMetadataFetcher(HttpClient::create()),
            new OAuth\RedirectPolicy($config->oauthRedirectHosts),
            $clock,
        );

        return new OAuth\OAuthService($pdo, $clients, $users, $clock, $config->baseUrl(), $config->resourceUrls(), $config->oauthAccessTokenTtl, $config->oauthRefreshTokenTtl);
    }

    /** Config path: $VAMSYS_MCP_CONFIG, else <project>/config/config.php. */
    public static function defaultConfigPath(): string
    {
        return (new Paths())->configFile();
    }

    public static function boot(?string $configPath = null): self
    {
        $config = Config::fromFile($configPath ?? self::defaultConfigPath());
        $pdo = $config->createPdo();
        $clock = new SystemClock();
        $psr17 = new Psr17Factory();
        // No redirects: vAMSYS answers auth problems with redirects to HTML pages.
        $http = new Psr18Client(
            HttpClient::create(['max_redirects' => 0, 'timeout' => $config->httpTimeoutSeconds]),
            $psr17,
            $psr17,
        );

        $rateLimiter = new RateLimiter($pdo, $clock, $config->maxRateWaitSeconds);
        $tokens = new TokenStore($pdo, $http, $psr17, $psr17, $rateLimiter, $clock, $config->tokenUrl, $config->tokenRefreshMarginSeconds);
        $callLog = $config->auditLog ? new CallLog($pdo, $clock) : null;

        return new self($config, $pdo, new VamsysClient($config, $tokens, $rateLimiter, $http, $psr17, $callLog));
    }

    public function serverBuilder(): Builder
    {
        $clock = new SystemClock();
        $c = $this->config;
        $routes = new RouteCache($this->pdo, $this->client, $clock, $c->routeCacheTtl, $c->scanMaxPages, (float) $c->scanTimeBudgetSeconds);
        $query = new RouteQuery(new AirportCache($this->pdo, $this->client, $clock, $c->airportCacheTtl, $c->scanMaxPages));

        $container = new Container();
        $container->set(ListAirlinesTool::class, new ListAirlinesTool($c));
        $container->set(FindRoutesTool::class, new FindRoutesTool($this->client, $routes, $query, $c->maxPagesPerCall, $c->routeCacheTtl));
        $container->set(CountRoutesTool::class, new CountRoutesTool($routes, $query, $c->routeCacheTtl));

        $readOnly = static fn (string $title): ToolAnnotations => new ToolAnnotations(
            title: $title,
            readOnlyHint: true,
            destructiveHint: false,
            idempotentHint: true,
            openWorldHint: true,
        );

        return Server::builder()
            ->setServerInfo(self::NAME, self::VERSION)
            ->setInstructions('Read-only access to vAMSYS virtual airline data. Call list_airlines first to get airline keys.')
            ->setContainer($container)
            ->addTool(ListAirlinesTool::class, ListAirlinesTool::NAME, description: ListAirlinesTool::DESCRIPTION, annotations: $readOnly('List airlines'))
            ->addTool(FindRoutesTool::class, FindRoutesTool::NAME, description: FindRoutesTool::DESCRIPTION, annotations: $readOnly('Find routes'))
            ->addTool(CountRoutesTool::class, CountRoutesTool::NAME, description: CountRoutesTool::DESCRIPTION, annotations: $readOnly('Count routes'));
    }
}
