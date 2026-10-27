<?php

declare(strict_types=1);

/*
 * HTTP entry point for PHP-FPM / shared hosting. One request in, one response
 * out; no long-running process. Routes:
 *
 *   /mcp (and /)                          MCP Streamable HTTP endpoint, OAuth-protected
 *   /.well-known/oauth-*, /oauth/*        OAuth authorization server (src/OAuth)
 *
 * Only public/ belongs in the web root, and it must be served at the root of
 * its (sub)domain. If your web root is elsewhere, put a one-line stub there
 * instead of a copy, so updates keep working:
 *   <?php require '/home/you/vamsys-mcp/public/index.php';
 */

use Http\Discovery\Psr17Factory;
use Laminas\HttpHandlerRunner\Emitter\SapiEmitter;
use Mcp\Server\Session\FileSessionLock;
use Mcp\Server\Session\FileSessionStore;
use Mcp\Server\Transport\Http\Middleware\AuthorizationMiddleware;
use Mcp\Server\Transport\Http\Middleware\CorsMiddleware;
use Mcp\Server\Transport\Http\Middleware\DnsRebindingProtectionMiddleware;
use Mcp\Server\Transport\Http\OAuth\ProtectedResourceMetadata;
use Mcp\Server\Transport\StreamableHttpTransport;
use VamsysMcp\App;
use VamsysMcp\OAuth\McpUsers;
use VamsysMcp\OAuth\OAuthApp;
use VamsysMcp\OAuth\OAuthService;
use VamsysMcp\OAuth\TokenValidator;
use VamsysMcp\Paths;

$projectRoot = dirname(__DIR__);

// Files are being replaced by an update: answer before loading any code.
if (is_file($projectRoot . '/var/maintenance')) {
    http_response_code(503);
    header('Retry-After: 30');
    header('Content-Type: application/json');
    echo '{"error":"The MCP server is being updated; try again in a minute."}';
    exit;
}

require $projectRoot . '/vendor/autoload.php';

$psr17 = new Psr17Factory();
$path = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/';

try {
    $app = App::boot();
    $config = $app->config;
    $host = strtolower((string) preg_replace('/:\d+$/', '', (string) ($_SERVER['HTTP_HOST'] ?? '')));
    if (!in_array($host, array_map('strtolower', $config->httpAllowedHosts), true)) {
        $response = $psr17->createResponse(403)->withHeader('Content-Type', 'application/json')
            ->withBody($psr17->createStream('{"error":"Unknown host name; add it to http.allowed_hosts."}'));
    } elseif (OAuthApp::handles($path)) {
        (new OAuthApp($config, App::oauth($config, $app->pdo), new McpUsers($app->pdo), $app->pdo, new Paths($projectRoot)))->run($path);
        exit;
    } elseif (!in_array($path, ['/', '/mcp'], true)) {
        $response = $psr17->createResponse(404)->withHeader('Content-Type', 'application/json')
            ->withBody($psr17->createStream('{"error":"Not found. The MCP endpoint is /mcp."}'));
    } else {
        // Sessions are only used by clients on the handshake-era protocol
        // (<= 2025-11-25); 2026-07-28 clients are served statelessly.
        $sessionDir = $config->sessionDir ?? $projectRoot . '/var/sessions';
        $server = $app->serverBuilder()
            ->setSession(new FileSessionStore($sessionDir))
            ->setSessionLock(new FileSessionLock($sessionDir))
            ->build();

        $resource = $config->baseUrl() . ($path === '/mcp' ? '/mcp' : '');
        $transport = new StreamableHttpTransport(
            $psr17->createServerRequestFromGlobals(),
            $psr17,
            $psr17,
            middleware: [
                new CorsMiddleware(),
                new DnsRebindingProtectionMiddleware(allowedHosts: $config->httpAllowedHosts),
                new AuthorizationMiddleware(
                    new TokenValidator(App::oauth($config, $app->pdo)),
                    new ProtectedResourceMetadata($resource, [$config->baseUrl()], [OAuthService::SCOPE], 'vAMSYS MCP'),
                    responseFactory: $psr17,
                    streamFactory: $psr17,
                ),
            ],
        );
        $response = $server->run($transport);
    }
} catch (Throwable $e) {
    // Log the class only: messages from PDO etc. can contain connection details.
    error_log('vamsys-mcp: unhandled ' . $e::class);
    $response = $psr17->createResponse(500)
        ->withHeader('Content-Type', 'application/json')
        ->withBody($psr17->createStream('{"error":"Internal server error"}'));
}

(new SapiEmitter())->emit($response);
