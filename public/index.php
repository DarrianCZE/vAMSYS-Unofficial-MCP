<?php

declare(strict_types=1);

/*
 * Streamable HTTP entry point for PHP-FPM / shared hosting. One request in,
 * one response out; no long-running process.
 *
 * Only public/ belongs in the web root. If your web root is elsewhere, put a
 * one-line stub there instead of a copy, so updates keep working:
 *   <?php require '/home/you/vamsys-mcp/public/index.php';
 */

use Http\Discovery\Psr17Factory;
use Laminas\HttpHandlerRunner\Emitter\SapiEmitter;
use Mcp\Server\Session\FileSessionLock;
use Mcp\Server\Session\FileSessionStore;
use Mcp\Server\Transport\Http\Middleware\CorsMiddleware;
use Mcp\Server\Transport\Http\Middleware\DnsRebindingProtectionMiddleware;
use Mcp\Server\Transport\StreamableHttpTransport;
use VamsysMcp\App;
use VamsysMcp\Http\BearerAuthMiddleware;

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

try {
    $app = App::boot();
    $config = $app->config;

    // Sessions are only used by clients on the handshake-era protocol
    // (<= 2025-11-25); 2026-07-28 clients are served statelessly.
    $sessionDir = $config->sessionDir ?? $projectRoot . '/var/sessions';
    $server = $app->serverBuilder()
        ->setSession(new FileSessionStore($sessionDir))
        ->setSessionLock(new FileSessionLock($sessionDir))
        ->build();

    $transport = new StreamableHttpTransport(
        $psr17->createServerRequestFromGlobals(),
        $psr17,
        $psr17,
        middleware: [
            new CorsMiddleware(),
            new DnsRebindingProtectionMiddleware(allowedHosts: $config->httpAllowedHosts),
            new BearerAuthMiddleware($config->httpBearerTokenHashes, $psr17, $psr17),
        ],
    );
    $response = $server->run($transport);
} catch (Throwable $e) {
    // Log the class only: messages from PDO etc. can contain connection details.
    error_log('vamsys-mcp: unhandled ' . $e::class);
    $response = $psr17->createResponse(500)
        ->withHeader('Content-Type', 'application/json')
        ->withBody($psr17->createStream('{"error":"Internal server error"}'));
}

(new SapiEmitter())->emit($response);
