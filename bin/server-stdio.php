<?php

declare(strict_types=1);

/*
 * STDIO entry point for local MCP clients (Claude Code, Claude Desktop, MCP Inspector).
 * Nothing may be written to STDOUT except MCP messages; errors go to STDERR.
 */

use Mcp\Server\Transport\StdioTransport;
use VamsysMcp\App;

require dirname(__DIR__) . '/vendor/autoload.php';

try {
    $server = App::boot()->serverBuilder()->build();
} catch (Throwable $e) {
    fwrite(STDERR, 'vamsys-mcp: failed to start: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}

exit($server->run(new StdioTransport()));
