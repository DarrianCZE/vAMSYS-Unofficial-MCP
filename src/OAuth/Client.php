<?php

declare(strict_types=1);

namespace VamsysMcp\OAuth;

/** An OAuth client: registered via DCR, or identified by a Client ID Metadata Document (CIMD). */
final class Client
{
    /**
     * @param list<string> $redirectUris
     */
    public function __construct(
        public readonly string $clientId,
        public readonly string $name,
        public readonly array $redirectUris,
        public readonly string $kind,
    ) {
    }

    /**
     * Exact match, except loopback redirects (Claude Code, MCP Inspector),
     * which match on scheme, host and path with any port (RFC 8252 §7.3).
     */
    public function allowsRedirect(string $uri): bool
    {
        foreach ($this->redirectUris as $registered) {
            if ($registered === $uri) {
                return true;
            }
            $a = RedirectPolicy::loopbackKey($registered);
            if ($a !== null && $a === RedirectPolicy::loopbackKey($uri)) {
                return true;
            }
        }

        return false;
    }
}
