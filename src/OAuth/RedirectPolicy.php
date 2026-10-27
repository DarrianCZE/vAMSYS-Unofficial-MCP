<?php

declare(strict_types=1);

namespace VamsysMcp\OAuth;

/**
 * Which redirect URIs this authorization server will send codes to.
 *
 * Anyone can register a client (DCR), so without a limit an attacker could
 * register their own site and phish an approval. Only hosts the operator
 * allows (default: claude.ai, plus loopback for Claude Code and the MCP
 * Inspector) are accepted; the consent page also shows the host.
 */
final class RedirectPolicy
{
    public const array DEFAULT_HOSTS = ['claude.ai', 'localhost', '127.0.0.1'];
    private const array LOOPBACK = ['localhost', '127.0.0.1', '[::1]', '::1'];

    /**
     * @param list<string> $allowedHosts
     */
    public function __construct(private readonly array $allowedHosts = self::DEFAULT_HOSTS)
    {
    }

    public function isAllowed(string $uri): bool
    {
        $parts = parse_url($uri);
        if ($parts === false || !isset($parts['scheme'], $parts['host']) || isset($parts['fragment']) || isset($parts['user']) || isset($parts['pass'])) {
            return false;
        }
        $host = strtolower($parts['host']);
        $scheme = strtolower($parts['scheme']);
        $loopback = in_array($host, self::LOOPBACK, true);
        if ($scheme !== 'https' && !($scheme === 'http' && $loopback)) {
            return false;
        }

        return in_array(trim($host, '[]'), array_map(static fn (string $h): string => strtolower(trim($h, '[]')), $this->allowedHosts), true);
    }

    /** "http|host|path" for loopback URIs (port ignored), null otherwise. */
    public static function loopbackKey(string $uri): ?string
    {
        $parts = parse_url($uri);
        if ($parts === false || strtolower($parts['scheme'] ?? '') !== 'http' || !in_array(strtolower($parts['host'] ?? ''), self::LOOPBACK, true)) {
            return null;
        }

        return 'http|' . strtolower($parts['host']) . '|' . ($parts['path'] ?? '/') . '|' . ($parts['query'] ?? '');
    }

    public static function host(string $uri): string
    {
        return strtolower((string) parse_url($uri, PHP_URL_HOST));
    }
}
