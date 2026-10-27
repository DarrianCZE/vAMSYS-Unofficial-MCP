<?php

declare(strict_types=1);

namespace VamsysMcp\OAuth;

use Psr\Clock\ClockInterface;
use VamsysMcp\Sql;

/**
 * Minimal OAuth 2.1 authorization server for MCP clients: authorization code
 * flow with mandatory PKCE (S256), public clients only, resource indicators
 * (RFC 8707), rotating refresh tokens with reuse detection.
 *
 * Codes and tokens are random strings stored only as SHA-256 hashes; a DB dump
 * reveals no usable token.
 */
final class OAuthService
{
    public const string SCOPE = 'vamsys:read';
    private const int CODE_TTL = 120;

    /**
     * @param list<string> $resources normalized resource URLs this server accepts (first = default)
     */
    public function __construct(
        private readonly \PDO $pdo,
        private readonly ClientRegistry $clients,
        private readonly McpUsers $users,
        private readonly ClockInterface $clock,
        private readonly string $issuer,
        private readonly array $resources,
        private readonly int $accessTokenTtl = 3600,
        private readonly int $refreshTokenTtl = 30 * 86400,
    ) {
    }

    /**
     * RFC 8414 authorization server metadata.
     *
     * @return array<string, mixed>
     */
    public function metadata(): array
    {
        return [
            'issuer' => $this->issuer,
            'authorization_endpoint' => $this->issuer . '/oauth/authorize',
            'token_endpoint' => $this->issuer . '/oauth/token',
            'registration_endpoint' => $this->issuer . '/oauth/register',
            'response_types_supported' => ['code'],
            'grant_types_supported' => ['authorization_code', 'refresh_token'],
            'code_challenge_methods_supported' => ['S256'],
            'token_endpoint_auth_methods_supported' => ['none'],
            'scopes_supported' => [self::SCOPE, 'offline_access'],
            'client_id_metadata_document_supported' => true,
            'authorization_response_iss_parameter_supported' => true,
        ];
    }

    /**
     * Dynamic Client Registration (RFC 7591).
     *
     * @param array<string, mixed> $request
     *
     * @return array<string, mixed>
     */
    public function registerClient(array $request): array
    {
        return $this->clients->register($request);
    }

    /**
     * Validate an authorization request. Errors about the client or redirect URI
     * are not redirectable (the target is untrusted); later errors are.
     *
     * @param array<string, mixed> $q query parameters
     */
    public function parseAuthorizationRequest(array $q): AuthorizationRequest
    {
        $clientId = (string) ($q['client_id'] ?? '');
        $client = $clientId === '' ? null : $this->clients->find($clientId);
        if ($client === null) {
            throw new OAuthException('invalid_client', 'Unknown client. Remove the connector and add it again.');
        }
        $redirectUri = (string) ($q['redirect_uri'] ?? '');
        if ($redirectUri === '' || !$client->allowsRedirect($redirectUri)) {
            throw new OAuthException('invalid_request', 'The redirect URI is not registered for this client or not allowed by this server.');
        }

        $state = isset($q['state']) ? (string) $q['state'] : null;
        $fail = static fn (string $error, string $message): OAuthException => new OAuthException($error, $message, 400, true);
        if (($q['response_type'] ?? '') !== 'code') {
            throw $fail('unsupported_response_type', 'Only response_type=code is supported.');
        }
        $challenge = (string) ($q['code_challenge'] ?? '');
        if (($q['code_challenge_method'] ?? '') !== 'S256' || preg_match('/^[A-Za-z0-9_-]{43,128}$/', $challenge) !== 1) {
            throw $fail('invalid_request', 'PKCE with code_challenge_method=S256 is required.');
        }
        $resource = $this->normalizeResource(isset($q['resource']) ? (string) $q['resource'] : null);
        if ($resource === null) {
            throw $fail('invalid_target', 'The requested resource is not served by this authorization server.');
        }

        return new AuthorizationRequest($client->clientId, $client->name, $redirectUri, $challenge, $resource, self::SCOPE, $state);
    }

    /** Issue a code for an approved request and return the URL to redirect the browser to. */
    public function approve(AuthorizationRequest $request, int $userId): string
    {
        $code = self::random('vmcp_ac_');
        $this->pdo->prepare('INSERT INTO mcp_oauth_code (code_hash, client_id, user_id, redirect_uri, code_challenge, resource, scope, grant_id, expires_at, used)
            VALUES (:h, :c, :u, :r, :ch, :res, :s, :g, :e, 0)')->execute([
                'h' => hash('sha256', $code), 'c' => $request->clientId, 'u' => $userId, 'r' => $request->redirectUri,
                'ch' => $request->codeChallenge, 'res' => $request->resource, 's' => $request->scope,
                'g' => bin2hex(random_bytes(16)), 'e' => $this->at(self::CODE_TTL),
            ]);

        return $this->redirectWith($request, ['code' => $code]);
    }

    public function denyUrl(AuthorizationRequest $request): string
    {
        return $this->redirectWith($request, ['error' => 'access_denied', 'error_description' => 'The user denied access.']);
    }

    public function errorRedirectUrl(AuthorizationRequest|array $request, OAuthException $e): string
    {
        $redirectUri = $request instanceof AuthorizationRequest ? $request->redirectUri : (string) $request['redirect_uri'];
        $state = $request instanceof AuthorizationRequest ? $request->state : ($request['state'] ?? null);

        return self::appendQuery($redirectUri, array_filter(['error' => $e->error, 'error_description' => $e->getMessage(), 'state' => $state, 'iss' => $this->issuer], static fn ($v) => $v !== null));
    }

    /**
     * Token endpoint (form-urlencoded body).
     *
     * @param array<string, mixed> $form
     *
     * @return array<string, mixed>
     */
    public function token(array $form): array
    {
        $this->collectGarbage();

        return match ($form['grant_type'] ?? '') {
            'authorization_code' => $this->exchangeCode($form),
            'refresh_token' => $this->refresh($form),
            default => throw new OAuthException('unsupported_grant_type', 'Supported grant types: authorization_code, refresh_token.'),
        };
    }

    /**
     * @return array{user_id: int, client_id: string, scope: string, resource: string}|null
     */
    public function validateAccessToken(string $token): ?array
    {
        $row = $this->tokenRow($token, 'access');
        if ($row === null || (int) $row['revoked'] === 1 || $this->expired((string) $row['expires_at'])
            || !in_array($row['resource'], $this->resources, true) || !$this->users->isActive((int) $row['user_id'])) {
            return null;
        }

        return ['user_id' => (int) $row['user_id'], 'client_id' => (string) $row['client_id'], 'scope' => (string) $row['scope'], 'resource' => (string) $row['resource']];
    }

    /**
     * Active connections (one per grant), for the admin page.
     *
     * @return list<array<string, mixed>>
     */
    public function activeGrants(): array
    {
        $select = $this->pdo->prepare(
            'SELECT t.grant_id, MIN(t.created_at) AS connected_at, MAX(t.created_at) AS last_refresh, MAX(t.expires_at) AS expires_at,
                    c.client_name, u.username
             FROM mcp_oauth_token t
             LEFT JOIN mcp_oauth_client c ON c.client_id = t.client_id
             LEFT JOIN mcp_user u ON u.id = t.user_id
             WHERE t.revoked = 0 AND t.kind = :k AND t.expires_at > :now
             GROUP BY t.grant_id, c.client_name, u.username
             ORDER BY last_refresh DESC',
        );
        $select->execute(['k' => 'refresh', 'now' => Sql::now($this->clock->now())]);

        return $select->fetchAll(\PDO::FETCH_ASSOC);
    }

    public function revokeGrant(string $grantId): void
    {
        $this->pdo->prepare('UPDATE mcp_oauth_token SET revoked = 1 WHERE grant_id = :g')->execute(['g' => $grantId]);
    }

    /**
     * Normalize and check a resource indicator; null resource = this server's default.
     */
    public function normalizeResource(?string $resource): ?string
    {
        if ($resource === null || $resource === '') {
            return $this->resources[0];
        }
        $normalized = rtrim($resource, '/');

        return in_array($normalized, $this->resources, true) ? $normalized : null;
    }

    /**
     * @param array<string, mixed> $form
     *
     * @return array<string, mixed>
     */
    private function exchangeCode(array $form): array
    {
        $code = (string) ($form['code'] ?? '');
        $select = $this->pdo->prepare('SELECT * FROM mcp_oauth_code WHERE code_hash = :h');
        $select->execute(['h' => hash('sha256', $code)]);
        $row = $select->fetch(\PDO::FETCH_ASSOC);
        if (!is_array($row) || $this->expired((string) $row['expires_at'])) {
            throw new OAuthException('invalid_grant', 'The authorization code is invalid or expired.');
        }

        // Single use, atomically. A replayed code means it leaked: revoke what it produced.
        $claim = $this->pdo->prepare('UPDATE mcp_oauth_code SET used = 1 WHERE code_hash = :h AND used = 0');
        $claim->execute(['h' => $row['code_hash']]);
        if ($claim->rowCount() !== 1) {
            $this->revokeGrant((string) $row['grant_id']);
            throw new OAuthException('invalid_grant', 'The authorization code was already used.');
        }

        if (($form['client_id'] ?? '') !== $row['client_id']) {
            throw new OAuthException('invalid_grant', 'The authorization code was issued to another client.');
        }
        if (($form['redirect_uri'] ?? '') !== $row['redirect_uri']) {
            throw new OAuthException('invalid_grant', 'redirect_uri does not match the authorization request.');
        }
        $verifier = (string) ($form['code_verifier'] ?? '');
        $expected = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
        if (preg_match('/^[A-Za-z0-9._~-]{43,128}$/', $verifier) !== 1 || !hash_equals((string) $row['code_challenge'], $expected)) {
            throw new OAuthException('invalid_grant', 'PKCE verification failed.');
        }
        if (isset($form['resource']) && $this->normalizeResource((string) $form['resource']) !== $row['resource']) {
            throw new OAuthException('invalid_target', 'The resource does not match the authorization request.');
        }
        if (!$this->users->isActive((int) $row['user_id'])) {
            throw new OAuthException('invalid_grant', 'The user is disabled.');
        }

        return $this->issue((string) $row['grant_id'], (string) $row['client_id'], (int) $row['user_id'], (string) $row['resource'], (string) $row['scope']);
    }

    /**
     * @param array<string, mixed> $form
     *
     * @return array<string, mixed>
     */
    private function refresh(array $form): array
    {
        $row = $this->tokenRow((string) ($form['refresh_token'] ?? ''), 'refresh');
        if ($row === null) {
            throw new OAuthException('invalid_grant', 'The refresh token is invalid.');
        }
        if ((int) $row['revoked'] === 1) {
            // A rotated-out refresh token came back: it was stolen or replayed. Kill the whole connection.
            $this->revokeGrant((string) $row['grant_id']);
            throw new OAuthException('invalid_grant', 'The refresh token was already used; the connection was revoked for safety. Reconnect.');
        }
        if ($this->expired((string) $row['expires_at']) || !$this->users->isActive((int) $row['user_id'])) {
            throw new OAuthException('invalid_grant', 'The refresh token expired or the user was disabled.');
        }
        if (isset($form['client_id']) && $form['client_id'] !== $row['client_id']) {
            throw new OAuthException('invalid_grant', 'The refresh token was issued to another client.');
        }

        $claim = $this->pdo->prepare('UPDATE mcp_oauth_token SET revoked = 1 WHERE token_hash = :h AND revoked = 0');
        $claim->execute(['h' => $row['token_hash']]);
        if ($claim->rowCount() !== 1) {
            throw new OAuthException('invalid_grant', 'The refresh token was already used.');
        }
        // Access tokens of the previous rotation are retired with their refresh token.
        $this->pdo->prepare('UPDATE mcp_oauth_token SET revoked = 1 WHERE grant_id = :g AND kind = :k')->execute(['g' => $row['grant_id'], 'k' => 'access']);

        return $this->issue((string) $row['grant_id'], (string) $row['client_id'], (int) $row['user_id'], (string) $row['resource'], (string) $row['scope']);
    }

    /**
     * @return array<string, mixed>
     */
    private function issue(string $grantId, string $clientId, int $userId, string $resource, string $scope): array
    {
        $access = self::random('vmcp_at_');
        $refresh = self::random('vmcp_rt_');
        $insert = $this->pdo->prepare('INSERT INTO mcp_oauth_token (token_hash, kind, grant_id, client_id, user_id, resource, scope, created_at, expires_at, revoked)
            VALUES (:h, :k, :g, :c, :u, :r, :s, :now, :e, 0)');
        foreach ([[$access, 'access', $this->accessTokenTtl], [$refresh, 'refresh', $this->refreshTokenTtl]] as [$token, $kind, $ttl]) {
            $insert->execute(['h' => hash('sha256', $token), 'k' => $kind, 'g' => $grantId, 'c' => $clientId, 'u' => $userId,
                'r' => $resource, 's' => $scope, 'now' => Sql::now($this->clock->now()), 'e' => $this->at($ttl)]);
        }

        return [
            'access_token' => $access,
            'token_type' => 'Bearer',
            'expires_in' => $this->accessTokenTtl,
            'refresh_token' => $refresh,
            'scope' => $scope,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function tokenRow(string $token, string $kind): ?array
    {
        if ($token === '') {
            return null;
        }
        $select = $this->pdo->prepare('SELECT * FROM mcp_oauth_token WHERE token_hash = :h AND kind = :k');
        $select->execute(['h' => hash('sha256', $token), 'k' => $kind]);
        $row = $select->fetch(\PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    private function collectGarbage(): void
    {
        if (random_int(1, 50) !== 1) {
            return;
        }
        $now = Sql::now($this->clock->now());
        $this->pdo->prepare('DELETE FROM mcp_oauth_code WHERE expires_at < :now')->execute(['now' => $now]);
        $this->pdo->prepare('DELETE FROM mcp_oauth_token WHERE expires_at < :now')->execute(['now' => $now]);
    }

    /**
     * @param array<string, string> $params
     */
    private function redirectWith(AuthorizationRequest $request, array $params): string
    {
        if ($request->state !== null) {
            $params['state'] = $request->state;
        }
        $params['iss'] = $this->issuer;

        return self::appendQuery($request->redirectUri, $params);
    }

    /**
     * @param array<string, string> $params
     */
    private static function appendQuery(string $uri, array $params): string
    {
        return $uri . (str_contains($uri, '?') ? '&' : '?') . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
    }

    private function expired(string $expiresAt): bool
    {
        return strtotime($expiresAt . ' UTC') <= $this->clock->now()->getTimestamp();
    }

    private function at(int $seconds): string
    {
        return gmdate('Y-m-d H:i:s', $this->clock->now()->getTimestamp() + $seconds);
    }

    private static function random(string $prefix): string
    {
        return $prefix . rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }
}
