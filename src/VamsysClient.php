<?php

declare(strict_types=1);

namespace VamsysMcp;

use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use VamsysMcp\Exception\ForbiddenRequestException;
use VamsysMcp\Exception\PilotsApiDisabledException;
use VamsysMcp\Exception\RateLimitExceededException;
use VamsysMcp\Exception\VamsysApiException;

/**
 * Read-only client for the vAMSYS Operations API.
 *
 * Only GET is implemented, on purpose: v1 must never send POST/PUT/PATCH/DELETE
 * to vAMSYS. Every request goes through the per-airline rate limiter, sends
 * Authorization + Accept: application/json, and is rejected unless the
 * response really is JSON.
 */
final class VamsysClient
{
    public function __construct(
        private readonly Config $config,
        private readonly TokenStore $tokens,
        private readonly RateLimiter $rateLimiter,
        private readonly ClientInterface $http,
        private readonly RequestFactoryInterface $requestFactory,
        private readonly ?CallLog $callLog = null,
    ) {
    }

    /**
     * @param array<string, scalar> $query
     * @param int|null              $maxRateWait seconds to wait for the rate limiter; 0 = throw at once when the cap is reached
     *
     * @return array<mixed> decoded JSON body
     */
    public function get(string $airlineKey, string $path, array $query = [], ?int $maxRateWait = null): array
    {
        $airline = $this->config->airline($airlineKey);
        $this->assertPathAllowed($airline, $path);
        $query = $this->applyWeightUnit($airline, $path, $query);

        $url = $this->config->apiBaseUrl . $path . ($query === [] ? '' : '?' . http_build_query($query));

        // One retry after a 401 with a fresh token (cached token revoked or expired early).
        for ($attempt = 0; ; ++$attempt) {
            $this->rateLimiter->acquire($airline->key, $airline->rateCap, $maxRateWait);
            $request = $this->requestFactory->createRequest('GET', $url)
                ->withHeader('Authorization', 'Bearer ' . $this->tokens->getToken($airline))
                ->withHeader('Accept', 'application/json');

            $started = hrtime(true);
            try {
                $response = $this->http->sendRequest($request);
            } catch (ClientExceptionInterface) {
                throw new VamsysApiException("Could not reach vAMSYS for GET {$path}.");
            }
            $this->callLog?->record($airline->key, 'GET', $path, $response->getStatusCode(), intdiv(hrtime(true) - $started, 1_000_000));

            if ($response->getStatusCode() === 401 && $attempt === 0) {
                $this->tokens->invalidate($airline->key);
                continue;
            }

            return $this->decode($response, $path);
        }
    }

    /** page[size] used for paginated lists (config vamsys.page_size). */
    public function pageSize(): int
    {
        return $this->config->pageSize;
    }

    /**
     * Iterate over every item of a cursor-paginated collection. Pages are
     * fetched strictly one after another (the cursor of page N comes from
     * page N-1), and at most $maxPages pages are fetched.
     *
     * The generator's return value is true if the collection was read to the
     * end, false if it stopped at $maxPages.
     *
     * @param array<string, scalar> $query
     *
     * @return \Generator<int, array<mixed>, mixed, bool>
     */
    public function paginate(string $airlineKey, string $path, array $query = [], ?int $maxPages = null): \Generator
    {
        $maxPages ??= $this->config->maxPagesPerCall;
        $query['page[size]'] ??= $this->config->pageSize;

        for ($page = 1; $page <= $maxPages; ++$page) {
            $body = $this->get($airlineKey, $path, $query);
            foreach ((array) ($body['data'] ?? []) as $item) {
                yield (array) $item;
            }
            $cursor = $body['meta']['next_cursor'] ?? null;
            if (!is_string($cursor) || $cursor === '') {
                return true;
            }
            $query['page[cursor]'] = $cursor;
        }

        return false;
    }

    private function assertPathAllowed(AirlineConfig $airline, string $path): void
    {
        if (!str_starts_with($path, '/') || str_contains($path, '..') || str_contains($path, '?') || str_contains($path, '//')) {
            throw new ForbiddenRequestException("Invalid API path '{$path}'.");
        }

        // Any path with a "pilots"/"pilot" segment (e.g. /pilots, /hubs/{id}/pilots,
        // /statistics/pilot/{id}) counts as the Pilots API.
        $segments = explode('/', strtolower($path));
        if (!$airline->pilotsApiEnabled && array_intersect($segments, ['pilots', 'pilot']) !== []) {
            throw new PilotsApiDisabledException(
                "The Pilots API is disabled for airline '{$airline->key}'. An operator must set 'pilots_api' => true in the config to allow it.",
            );
        }
    }

    /**
     * Every /routes call carries weight_unit as a query parameter, always the
     * airline's configured unit, so calls never go out with mixed units.
     *
     * @param array<string, scalar> $query
     *
     * @return array<string, scalar>
     */
    private function applyWeightUnit(AirlineConfig $airline, string $path, array $query): array
    {
        if ($path !== '/routes' && !str_starts_with($path, '/routes/')) {
            return $query;
        }
        if (isset($query['weight_unit']) && $query['weight_unit'] !== $airline->weightUnit) {
            throw new ForbiddenRequestException(sprintf(
                "Airline '%s' is configured for weight_unit '%s'; refusing a /routes call with '%s'.",
                $airline->key,
                $airline->weightUnit,
                (string) $query['weight_unit'],
            ));
        }
        $query['weight_unit'] = $airline->weightUnit;

        return $query;
    }

    /**
     * @return array<mixed>
     */
    private function decode(ResponseInterface $response, string $path): array
    {
        $status = $response->getStatusCode();

        if ($status === 429) {
            throw new RateLimitExceededException(
                "vAMSYS answered HTTP 429 for GET {$path}: the airline's 100 requests/minute limit (shared by all its OAuth clients) is exhausted. Try again in a minute.",
            );
        }

        // Without Accept: application/json vAMSYS answers with HTML (often a
        // redirect page with HTTP 200), so never trust the status alone.
        $contentType = strtolower($response->getHeaderLine('Content-Type'));
        $data = json_decode((string) $response->getBody(), true);
        if (!str_contains($contentType, 'json') || !is_array($data)) {
            throw new VamsysApiException(sprintf(
                'vAMSYS returned a non-JSON response for GET %s (HTTP %d, Content-Type "%s"). This usually means a missing Accept header, an authentication redirect or a wrong API base URL.',
                $path,
                $status,
                $contentType === '' ? 'none' : substr($contentType, 0, 60),
            ));
        }

        if ($status >= 400) {
            $message = is_string($data['message'] ?? null) ? ': ' . substr($data['message'], 0, 200) : '';
            throw new VamsysApiException("vAMSYS returned HTTP {$status} for GET {$path}{$message}");
        }
        if ($status >= 300) {
            throw new VamsysApiException("vAMSYS returned an unexpected redirect (HTTP {$status}) for GET {$path}.");
        }

        return $data;
    }
}
