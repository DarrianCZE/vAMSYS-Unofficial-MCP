<?php

declare(strict_types=1);

namespace VamsysMcp\OAuth;

use Psr\Clock\ClockInterface;
use VamsysMcp\Sql;

/**
 * OAuth clients: Dynamic Client Registration (RFC 7591) and Client ID
 * Metadata Documents (cached for a day). All clients are public clients
 * using PKCE; no client secrets exist.
 */
final class ClientRegistry
{
    private const int CIMD_CACHE_SECONDS = 86400;
    private const int MAX_REDIRECT_URIS = 10;

    public function __construct(
        private readonly \PDO $pdo,
        private readonly ClientMetadataFetcher $fetcher,
        private readonly RedirectPolicy $redirects,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * Handle a registration request body and return the RFC 7591 response.
     *
     * @param array<string, mixed> $request
     *
     * @return array<string, mixed>
     */
    public function register(array $request): array
    {
        $uris = $request['redirect_uris'] ?? null;
        if (!is_array($uris) || $uris === [] || count($uris) > self::MAX_REDIRECT_URIS || array_filter($uris, 'is_string') !== $uris) {
            throw new OAuthException('invalid_redirect_uri', 'redirect_uris must be a non-empty list of URLs.');
        }
        foreach ($uris as $uri) {
            if (!$this->redirects->isAllowed($uri)) {
                throw new OAuthException('invalid_redirect_uri', "Redirect URI {$uri} is not allowed by this server (allowed hosts are set by the operator).");
            }
        }
        $auth = $request['token_endpoint_auth_method'] ?? 'none';
        if ($auth !== 'none') {
            throw new OAuthException('invalid_client_metadata', 'Only public clients (token_endpoint_auth_method "none") are supported.');
        }
        foreach ((array) ($request['grant_types'] ?? ['authorization_code']) as $grant) {
            if (!in_array($grant, ['authorization_code', 'refresh_token'], true)) {
                throw new OAuthException('invalid_client_metadata', "Grant type {$grant} is not supported.");
            }
        }

        $clientId = 'dcr_' . bin2hex(random_bytes(16));
        $name = self::cleanName((string) ($request['client_name'] ?? ''), $uris[0]);
        Sql::upsert($this->pdo, 'mcp_oauth_client', 'client_id', [
            'client_id' => $clientId, 'kind' => 'dcr', 'client_name' => $name,
            'redirect_uris' => (string) json_encode(array_values($uris)), 'created_at' => Sql::now($this->clock->now()), 'fetched_at' => null,
        ]);

        return [
            'client_id' => $clientId,
            'client_id_issued_at' => $this->clock->now()->getTimestamp(),
            'client_name' => $name,
            'redirect_uris' => array_values($uris),
            'grant_types' => ['authorization_code', 'refresh_token'],
            'response_types' => ['code'],
            'token_endpoint_auth_method' => 'none',
        ];
    }

    public function find(string $clientId): ?Client
    {
        $select = $this->pdo->prepare('SELECT client_id, kind, client_name, redirect_uris, fetched_at FROM mcp_oauth_client WHERE client_id = :id');
        $select->execute(['id' => $clientId]);
        $row = $select->fetch(\PDO::FETCH_ASSOC);

        $isCimd = ClientMetadataFetcher::looksLikeMetadataUrl($clientId);
        $fresh = is_array($row) && ($row['kind'] === 'dcr'
            || ($row['fetched_at'] !== null && strtotime($row['fetched_at'] . ' UTC') > $this->clock->now()->getTimestamp() - self::CIMD_CACHE_SECONDS));
        if (!$fresh && $isCimd) {
            $meta = $this->fetcher->fetch($clientId);
            $uris = array_values(array_filter((array) $meta['redirect_uris'], fn (string $u): bool => $this->redirects->isAllowed($u)));
            Sql::upsert($this->pdo, 'mcp_oauth_client', 'client_id', [
                'client_id' => $clientId, 'kind' => 'cimd', 'client_name' => self::cleanName((string) ($meta['client_name'] ?? ''), $clientId),
                'redirect_uris' => (string) json_encode($uris), 'created_at' => Sql::now($this->clock->now()), 'fetched_at' => Sql::now($this->clock->now()),
            ]);

            return $this->find($clientId);
        }
        if (!is_array($row)) {
            return null;
        }

        return new Client((string) $row['client_id'], (string) $row['client_name'], array_values((array) json_decode((string) $row['redirect_uris'], true)), (string) $row['kind']);
    }

    private static function cleanName(string $name, string $fallbackUri): string
    {
        $name = trim(preg_replace('/[\x00-\x1F\x7F]/u', '', $name) ?? '');

        return mb_substr($name !== '' ? $name : (string) parse_url($fallbackUri, PHP_URL_HOST), 0, 100);
    }
}
