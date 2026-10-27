<?php

declare(strict_types=1);

namespace VamsysMcp\OAuth;

use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Fetches a Client ID Metadata Document (the client_id is an https URL that
 * serves the client's metadata, e.g. Claude's published identity).
 *
 * The URL comes from an untrusted request, so the fetch is locked down:
 * https only, no redirects, a short timeout, a size cap, and the host must
 * resolve to public IP addresses only (the connection is pinned to the
 * checked address, so DNS rebinding cannot swap in an internal one).
 */
final class ClientMetadataFetcher
{
    private const int MAX_BYTES = 64 * 1024;

    /** @var \Closure(string): list<string> */
    private readonly \Closure $resolver;

    /**
     * @param null|\Closure(string): list<string> $resolver host => IP addresses (injectable for tests)
     */
    public function __construct(private readonly HttpClientInterface $http, ?\Closure $resolver = null)
    {
        $this->resolver = $resolver ?? static function (string $host): array {
            $ips = gethostbynamel($host) ?: [];
            foreach (@dns_get_record($host, DNS_AAAA) ?: [] as $record) {
                $ips[] = (string) ($record['ipv6'] ?? '');
            }

            return array_values(array_filter($ips));
        };
    }

    public static function looksLikeMetadataUrl(string $clientId): bool
    {
        $parts = parse_url($clientId);

        return $parts !== false && ($parts['scheme'] ?? '') === 'https' && isset($parts['host']) && ($parts['path'] ?? '') !== '' && ($parts['path'] ?? '') !== '/'
            && !isset($parts['fragment']) && !isset($parts['user']);
    }

    /**
     * @return array<string, mixed> validated metadata
     */
    public function fetch(string $url): array
    {
        if (!self::looksLikeMetadataUrl($url)) {
            throw new OAuthException('invalid_client', 'The client_id is not a valid client metadata URL.');
        }
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $ips = ($this->resolver)($host);
        if ($ips === []) {
            throw new OAuthException('invalid_client', "The client metadata host {$host} cannot be resolved.");
        }
        foreach ($ips as $ip) {
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_GLOBAL_RANGE) === false) {
                throw new OAuthException('invalid_client', 'The client metadata URL points to a private or reserved address.');
            }
        }

        try {
            $response = $this->http->request('GET', $url, [
                'max_redirects' => 0,
                'timeout' => 5,
                'max_duration' => 8,
                'headers' => ['Accept' => 'application/json'],
                'resolve' => [$host => $ips[0]],
            ]);
            if ($response->getStatusCode() !== 200) {
                throw new OAuthException('invalid_client', "The client metadata URL answered HTTP {$response->getStatusCode()}.");
            }
            $body = '';
            foreach ($this->http->stream($response) as $chunk) {
                $body .= $chunk->getContent();
                if (strlen($body) > self::MAX_BYTES) {
                    $response->cancel();
                    throw new OAuthException('invalid_client', 'The client metadata document is too large.');
                }
            }
        } catch (ExceptionInterface) {
            throw new OAuthException('invalid_client', 'The client metadata document could not be fetched.');
        }

        $data = json_decode($body, true);
        if (!is_array($data) || ($data['client_id'] ?? null) !== $url) {
            throw new OAuthException('invalid_client', 'The client metadata document is invalid or its client_id does not match its URL.');
        }
        $uris = $data['redirect_uris'] ?? null;
        if (!is_array($uris) || $uris === [] || array_filter($uris, 'is_string') !== $uris) {
            throw new OAuthException('invalid_client', 'The client metadata document has no redirect_uris.');
        }
        $auth = $data['token_endpoint_auth_method'] ?? 'none';
        if ($auth !== 'none') {
            throw new OAuthException('invalid_client', 'Only public clients (token_endpoint_auth_method "none") are supported.');
        }

        return $data;
    }
}
