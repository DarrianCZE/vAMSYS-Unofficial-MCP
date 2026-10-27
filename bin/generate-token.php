<?php

declare(strict_types=1);

/*
 * Generates a random bearer token for the HTTP endpoint.
 * Put the hash in config 'http' => ['bearer_token_sha256' => [...]] and give
 * the token to your MCP client. The token itself is not stored anywhere.
 */

$token = bin2hex(random_bytes(32));

echo "Token (give to your MCP client, shown once):\n  {$token}\n\n";
echo "SHA-256 hash (put in config http.bearer_token_sha256):\n  " . hash('sha256', $token) . "\n";
