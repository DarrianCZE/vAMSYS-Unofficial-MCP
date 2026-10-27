<?php

declare(strict_types=1);

namespace VamsysMcp\Tests;

use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use VamsysMcp\Config;
use VamsysMcp\Exception\ForbiddenRequestException;
use VamsysMcp\Exception\PilotsApiDisabledException;
use VamsysMcp\Exception\RateLimitExceededException;
use VamsysMcp\Exception\VamsysApiException;
use VamsysMcp\RateLimiter;
use VamsysMcp\Tests\Support\FakeClock;
use VamsysMcp\Tests\Support\FakeHttpClient;
use VamsysMcp\Tests\Support\TestDatabase;
use VamsysMcp\TokenStore;
use VamsysMcp\VamsysClient;

final class VamsysClientTest extends TestCase
{
    private FakeHttpClient $http;

    protected function setUp(): void
    {
        $this->http = new FakeHttpClient();
    }

    /**
     * @param array<string, mixed> $airline
     */
    private function client(array $airline = [], int $rateCap = 60): VamsysClient
    {
        $config = Config::fromArray([
            'database' => ['dsn' => 'sqlite::memory:'],
            'vamsys' => ['api_base_url' => 'https://vamsys.test/api/v3/operations', 'token_url' => 'https://vamsys.test/oauth/token', 'page_size' => 2],
            'airlines' => ['swv' => $airline + [
                'client_id' => '42', 'client_secret' => 'secret', 'weight_unit' => 'lbs', 'rate_cap' => $rateCap,
            ]],
        ]);
        $pdo = TestDatabase::create();
        $clock = new FakeClock();
        $psr17 = new Psr17Factory();
        $limiter = new RateLimiter($pdo, $clock, 0);
        $tokens = new TokenStore($pdo, $this->http, $psr17, $psr17, $limiter, $clock, $config->tokenUrl);

        return new VamsysClient($config, $tokens, $limiter, $this->http, $psr17);
    }

    /** Answer token requests with a token and API requests with $api. */
    private function routeApi(\Closure $api): void
    {
        $this->http->route(static fn (RequestInterface $r) => str_ends_with($r->getUri()->getPath(), '/oauth/token')
            ? FakeHttpClient::token('tok-1')
            : $api($r));
    }

    /** @return list<RequestInterface> */
    private function apiRequests(): array
    {
        return array_values(array_filter($this->http->requests, static fn ($r) => !str_ends_with($r->getUri()->getPath(), '/oauth/token')));
    }

    public function testSendsBearerTokenAndAcceptJsonOnEveryRequest(): void
    {
        $this->routeApi(static fn () => FakeHttpClient::json(['data' => []]));
        $this->client()->get('swv', '/airports');

        $request = $this->apiRequests()[0];
        self::assertSame('GET', $request->getMethod());
        self::assertSame('Bearer tok-1', $request->getHeaderLine('Authorization'));
        self::assertSame('application/json', $request->getHeaderLine('Accept'));
    }

    public function testRoutesCallsAlwaysCarryConfiguredWeightUnitAsQueryParameter(): void
    {
        $this->routeApi(static fn () => FakeHttpClient::json(['data' => []]));
        $client = $this->client();
        $client->get('swv', '/routes');
        $client->get('swv', '/routes/7');
        $client->get('swv', '/airports');

        $queries = array_map(static function (RequestInterface $r): array {
            parse_str($r->getUri()->getQuery(), $q);

            return $q;
        }, $this->apiRequests());
        self::assertSame('lbs', $queries[0]['weight_unit']);
        self::assertSame('lbs', $queries[1]['weight_unit']);
        self::assertArrayNotHasKey('weight_unit', $queries[2]);
        self::assertSame('', (string) $this->apiRequests()[0]->getBody());
    }

    public function testRejectsRoutesCallWithConflictingWeightUnit(): void
    {
        $this->expectException(ForbiddenRequestException::class);
        $this->client()->get('swv', '/routes', ['weight_unit' => 'kg']);
    }

    public function testHtmlResponseWithStatus200IsRejected(): void
    {
        $this->routeApi(static fn () => new Response(200, ['Content-Type' => 'text/html; charset=UTF-8'], '<html>Redirecting…</html>'));

        $this->expectException(VamsysApiException::class);
        $this->expectExceptionMessageMatches('/non-JSON response for GET \/airports \(HTTP 200/');
        $this->client()->get('swv', '/airports');
    }

    public function testPilotEndpointsAreBlockedByDefault(): void
    {
        $client = $this->client();
        foreach (['/pilots', '/pilots/5', '/hubs/1/pilots', '/statistics/pilot/5', '/badges/2/pilots'] as $path) {
            try {
                $client->get('swv', $path);
                self::fail("{$path} should be blocked");
            } catch (PilotsApiDisabledException) {
            }
        }
        self::assertSame([], $this->http->requests);
    }

    public function testPilotEndpointsWorkWhenExplicitlyEnabled(): void
    {
        $this->routeApi(static fn () => FakeHttpClient::json(['data' => []]));
        $this->client(['pilots_api' => true])->get('swv', '/pilots');
        self::assertCount(1, $this->apiRequests());
    }

    public function testPaginatesSequentiallyWithCursor(): void
    {
        $this->routeApi(static function (RequestInterface $r) {
            parse_str($r->getUri()->getQuery(), $q);

            return match ($q['page']['cursor'] ?? null) {
                null => FakeHttpClient::json(['data' => [['id' => 1], ['id' => 2]], 'meta' => ['next_cursor' => 'c2']]),
                'c2' => FakeHttpClient::json(['data' => [['id' => 3]], 'meta' => ['next_cursor' => null]]),
            };
        });

        $pages = $this->client()->paginate('swv', '/airports');
        $ids = array_column(iterator_to_array($pages, false), 'id');

        self::assertSame([1, 2, 3], $ids);
        self::assertTrue($pages->getReturn());
        parse_str($this->apiRequests()[0]->getUri()->getQuery(), $first);
        self::assertSame('2', $first['page']['size']);
    }

    public function testRetriesOnceWithFreshTokenAfter401(): void
    {
        $tokens = 0;
        $api = 0;
        $this->http->route(static function (RequestInterface $r) use (&$tokens, &$api) {
            if (str_ends_with($r->getUri()->getPath(), '/oauth/token')) {
                return FakeHttpClient::token('tok-' . ++$tokens);
            }

            return ++$api === 1 ? FakeHttpClient::json(['message' => 'Unauthenticated.'], 401) : FakeHttpClient::json(['data' => []]);
        });

        $this->client()->get('swv', '/airports');
        self::assertSame('Bearer tok-2', $this->apiRequests()[1]->getHeaderLine('Authorization'));
    }

    public function testRateCapIsEnforcedBeforeSending(): void
    {
        $this->routeApi(static fn () => FakeHttpClient::json(['data' => []]));
        $client = $this->client(rateCap: 2); // token fetch + 1 API call

        $client->get('swv', '/airports');
        try {
            $client->get('swv', '/airports');
            self::fail('Expected rate cap');
        } catch (RateLimitExceededException) {
        }
        self::assertCount(2, $this->http->requests);
    }

    public function testErrorMessagesNeverContainTheToken(): void
    {
        $this->routeApi(static fn () => FakeHttpClient::json(['message' => 'Server Error'], 500));
        try {
            $this->client()->get('swv', '/airports');
            self::fail('Expected exception');
        } catch (VamsysApiException $e) {
            self::assertStringContainsString('HTTP 500', $e->getMessage());
            self::assertStringNotContainsString('tok-1', $e->getMessage());
        }
    }
}
