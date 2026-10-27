<?php

declare(strict_types=1);

namespace VamsysMcp;

use Psr\Clock\ClockInterface;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use VamsysMcp\Exception\VamsysApiException;

/**
 * Fetches vAMSYS OAuth client-credentials tokens and caches them per airline
 * in the oauth_token table, refreshing shortly before they expire.
 *
 * The token lifetime comes from expires_in in each token response. Never
 * hard-code it: the spec says 24 hours, operators have seen 7 days.
 */
final class TokenStore
{
    /** Used only if a token response has no usable expires_in. */
    private const int FALLBACK_LIFETIME_SECONDS = 3600;

    public function __construct(
        private readonly \PDO $pdo,
        private readonly ClientInterface $http,
        private readonly RequestFactoryInterface $requestFactory,
        private readonly StreamFactoryInterface $streamFactory,
        private readonly RateLimiter $rateLimiter,
        private readonly ClockInterface $clock,
        private readonly string $tokenUrl,
        private readonly int $refreshMarginSeconds = 300,
    ) {
    }

    public function getToken(AirlineConfig $airline): string
    {
        $select = $this->pdo->prepare('SELECT access_token, expires_at FROM oauth_token WHERE airline = :airline');
        $select->execute(['airline' => $airline->key]);
        $row = $select->fetch(\PDO::FETCH_ASSOC);

        $refreshAfter = $this->clock->now()->getTimestamp() + $this->refreshMarginSeconds;
        if (is_array($row) && strtotime($row['expires_at'] . ' UTC') > $refreshAfter) {
            return (string) $row['access_token'];
        }

        return $this->fetchAndStore($airline);
    }

    /** Drop the cached token, e.g. after vAMSYS answered 401. */
    public function invalidate(string $airline): void
    {
        $this->pdo->prepare('DELETE FROM oauth_token WHERE airline = :airline')->execute(['airline' => $airline]);
    }

    private function fetchAndStore(AirlineConfig $airline): string
    {
        $this->rateLimiter->acquire($airline->key, $airline->rateCap);

        // The body must be form-urlencoded: a JSON body causes intermittent HTTP 500s.
        $body = http_build_query([
            'grant_type' => 'client_credentials',
            'client_id' => $airline->clientId,
            'client_secret' => $airline->clientSecret(),
            'scope' => '*',
        ]);
        $request = $this->requestFactory->createRequest('POST', $this->tokenUrl)
            ->withHeader('Content-Type', 'application/x-www-form-urlencoded')
            ->withHeader('Accept', 'application/json')
            ->withBody($this->streamFactory->createStream($body));

        try {
            $response = $this->http->sendRequest($request);
        } catch (ClientExceptionInterface) {
            throw new VamsysApiException("Could not reach the vAMSYS token endpoint for airline '{$airline->key}'.");
        }

        $status = $response->getStatusCode();
        $data = json_decode((string) $response->getBody(), true);
        if ($status !== 200 || !is_array($data) || !is_string($data['access_token'] ?? null) || $data['access_token'] === '') {
            // Never echo the response body: it could contain token material.
            throw new VamsysApiException(sprintf(
                "vAMSYS token request for airline '%s' failed (HTTP %d). Check client_id/client_secret in the config.",
                $airline->key,
                $status,
            ));
        }

        $lifetime = is_int($data['expires_in'] ?? null) && $data['expires_in'] > 0
            ? $data['expires_in']
            : self::FALLBACK_LIFETIME_SECONDS;
        $expiresAt = gmdate('Y-m-d H:i:s', $this->clock->now()->getTimestamp() + $lifetime);

        $this->upsert($airline->key, $data['access_token'], $expiresAt);

        return $data['access_token'];
    }

    private function upsert(string $airline, string $token, string $expiresAt): void
    {
        $sql = match ($this->pdo->getAttribute(\PDO::ATTR_DRIVER_NAME)) {
            'mysql' => 'INSERT INTO oauth_token (airline, access_token, expires_at) VALUES (:airline, :token, :expires)
                ON DUPLICATE KEY UPDATE access_token = VALUES(access_token), expires_at = VALUES(expires_at)',
            'sqlite' => 'INSERT INTO oauth_token (airline, access_token, expires_at) VALUES (:airline, :token, :expires)
                ON CONFLICT (airline) DO UPDATE SET access_token = excluded.access_token, expires_at = excluded.expires_at',
            default => throw new \LogicException('Unsupported database driver for TokenStore.'),
        };
        $this->pdo->prepare($sql)->execute(['airline' => $airline, 'token' => $token, 'expires' => $expiresAt]);
    }
}
