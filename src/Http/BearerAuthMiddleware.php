<?php

declare(strict_types=1);

namespace VamsysMcp\Http;

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * v1 authentication for the HTTP endpoint: a static bearer token. The config
 * stores only SHA-256 hashes of accepted tokens. Fails closed when no hash is
 * configured.
 */
final class BearerAuthMiddleware implements MiddlewareInterface
{
    /**
     * @param list<string> $tokenHashes lowercase SHA-256 hex digests
     */
    public function __construct(
        private readonly array $tokenHashes,
        private readonly ResponseFactoryInterface $responses,
        private readonly StreamFactoryInterface $streams,
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        // Let CORS preflights through; they never carry credentials.
        if ($request->getMethod() === 'OPTIONS') {
            return $handler->handle($request);
        }
        if ($this->tokenHashes === []) {
            return $this->error(503, 'HTTP access is not configured (http.bearer_token_sha256 is empty).');
        }

        $header = $request->getHeaderLine('Authorization');
        if (preg_match('/^Bearer\s+(\S+)$/i', $header, $m) === 1) {
            $hash = hash('sha256', $m[1]);
            foreach ($this->tokenHashes as $allowed) {
                if (hash_equals($allowed, $hash)) {
                    return $handler->handle($request);
                }
            }
        }

        return $this->error(401, 'Missing or invalid bearer token.')
            ->withHeader('WWW-Authenticate', 'Bearer realm="vamsys-mcp"');
    }

    private function error(int $status, string $message): ResponseInterface
    {
        return $this->responses->createResponse($status)
            ->withHeader('Content-Type', 'application/json')
            ->withBody($this->streams->createStream((string) json_encode(['error' => $message])));
    }
}
