<?php

declare(strict_types=1);

namespace VamsysMcp\Tests;

use Mcp\Exception\ToolCallException;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use VamsysMcp\Cache\AirportCache;
use VamsysMcp\Cache\RouteCache;
use VamsysMcp\Config;
use VamsysMcp\RateLimiter;
use VamsysMcp\Tests\Support\FakeClock;
use VamsysMcp\Tests\Support\FakeHttpClient;
use VamsysMcp\Tests\Support\TestDatabase;
use VamsysMcp\TokenStore;
use VamsysMcp\Tools\CountRoutesTool;
use VamsysMcp\Tools\FindRoutesTool;
use VamsysMcp\Tools\RouteQuery;
use VamsysMcp\VamsysClient;

/**
 * find_routes / count_routes against a mocked vAMSYS with paged airports and
 * routes (page size 2), the airport cache and the route cache.
 */
final class FindRoutesToolTest extends TestCase
{
    /** @var list<array<string, mixed>> */
    private array $airports = [
        ['id' => 10, 'icao' => 'LKPR', 'iata' => 'PRG', 'name' => 'Prague'],
        ['id' => 20, 'icao' => 'EGLL', 'iata' => 'LHR', 'name' => 'London Heathrow'],
        ['id' => 30, 'icao' => 'KJFK', 'iata' => 'JFK', 'name' => 'New York JFK'],
    ];
    /** @var list<array<string, mixed>> */
    private array $routes = [
        ['id' => 1, 'flight_number' => 'SW1', 'departure_id' => 10, 'arrival_id' => 20, 'departure_time' => '08:00:00', 'tag' => ['Europe'], 'hidden' => false],
        ['id' => 2, 'flight_number' => 'SW2', 'departure_id' => 10, 'arrival_id' => 30, 'departure_time' => null, 'tag' => ['Longhaul'], 'hidden' => false],
        ['id' => 3, 'flight_number' => 'SW3', 'departure_id' => 20, 'arrival_id' => 10, 'departure_time' => null, 'tag' => ['Europe'], 'hidden' => true],
        ['id' => 4, 'flight_number' => 'SW4', 'departure_id' => 10, 'arrival_id' => 20, 'departure_time' => null, 'tag' => [], 'hidden' => false],
        ['id' => 5, 'flight_number' => 'SW5', 'departure_id' => 30, 'arrival_id' => 99, 'departure_time' => '09:00:00', 'tag' => ['Europe', 'Longhaul'], 'hidden' => false],
    ];

    private FakeHttpClient $http;
    private FakeClock $clock;
    private FindRoutesTool $find;
    private CountRoutesTool $count;
    private AirportCache $airportCache;
    private VamsysClient $client;
    private \PDO $pdo;

    protected function setUp(): void
    {
        $this->build(60);
    }

    private function build(int $rateCap): void
    {
        $this->http = new FakeHttpClient();
        $this->clock = new FakeClock('2026-01-01 12:00:10');
        $config = Config::fromArray([
            'database' => ['dsn' => 'sqlite::memory:'],
            'vamsys' => ['api_base_url' => 'https://vamsys.test/api', 'token_url' => 'https://vamsys.test/oauth/token', 'page_size' => 2],
            'airlines' => ['swv' => ['client_id' => '1', 'client_secret' => 'x', 'weight_unit' => 'kg', 'rate_cap' => $rateCap]],
        ]);
        $this->pdo = TestDatabase::create();
        $psr17 = new Psr17Factory();
        $limiter = new RateLimiter($this->pdo, $this->clock, 0);
        $tokens = new TokenStore($this->pdo, $this->http, $psr17, $psr17, $limiter, $this->clock, $config->tokenUrl);
        $client = $this->client = new VamsysClient($config, $tokens, $limiter, $this->http, $psr17);
        $this->airportCache = new AirportCache($this->pdo, $client, $this->clock, 86400);
        $query = new RouteQuery($this->airportCache);
        $routeCache = new RouteCache($this->pdo, $client, $this->clock, 600, 100, 20.0);
        $this->find = new FindRoutesTool($client, $routeCache, $query, 10, 600);
        $this->count = new CountRoutesTool($routeCache, $query, 600);

        $this->http->route(function (RequestInterface $r) {
            parse_str($r->getUri()->getQuery(), $q);
            $path = $r->getUri()->getPath();
            if ($path === '/oauth/token') {
                return FakeHttpClient::token();
            }
            $items = match ($path) {
                '/api/airports' => $this->airports,
                '/api/routes' => array_values(array_filter($this->routes, static fn (array $route): bool =>
                    (!isset($q['filter']['departure_id']) || (string) $route['departure_id'] === $q['filter']['departure_id'])
                    && (!isset($q['filter']['arrival_id']) || (string) $route['arrival_id'] === $q['filter']['arrival_id']))),
            };
            $offset = (int) ($q['page']['cursor'] ?? 0);
            $size = (int) $q['page']['size'];
            $next = $offset + $size < count($items) ? (string) ($offset + $size) : null;

            return FakeHttpClient::json(['data' => array_slice($items, $offset, $size), 'meta' => ['next_cursor' => $next]]);
        });
    }

    private function requests(string $path): int
    {
        return count(array_filter($this->http->requests, static fn (RequestInterface $r): bool => $r->getUri()->getPath() === $path));
    }

    /** @return array<string, mixed> */
    private function call(callable $tool, mixed ...$args): array
    {
        return $tool(...$args)->structuredContent;
    }

    public function testBothEndsComeBackAsIcaoAndFiltersResolveThroughTheCache(): void
    {
        $result = $this->call($this->find, 'swv', dep: 'PRG');

        self::assertSame('live', $result['source']);
        self::assertSame(['dep' => 'LKPR'], $result['filters']);
        self::assertSame(['id' => 1, 'flight_number' => 'SW1', 'dep' => 'LKPR', 'dep_iata' => 'PRG', 'arr' => 'EGLL', 'arr_iata' => 'LHR', 'dep_time' => '08:00:00', 'tags' => ['Europe']], $result['routes'][0]);
        self::assertSame('KJFK', $result['routes'][1]['arr']);
        self::assertSame(2, $this->requests('/api/airports'), 'airport list read once (2 pages)');

        $this->call($this->find, 'swv', dep: 'LKPR', arr: 'egll');
        self::assertSame(2, $this->requests('/api/airports'), 'ICAO/IATA filters resolved from the cache, no extra calls');
        foreach ($this->http->requests as $r) {
            self::assertStringNotContainsString('filter%5Bicao%5D', $r->getUri()->getQuery());
        }
    }

    public function testUnknownAirportIdReloadsOnceThenFallsBackToUnresolved(): void
    {
        // Cache knows only Prague and Heathrow; JFK appears later in vAMSYS.
        $all = $this->airports;
        $this->airports = array_slice($all, 0, 2);
        (new AirportCache($this->pdo, $this->client, $this->clock))->lookup('swv', [10]); // an earlier request
        $this->airports = $all;
        $this->clock->advance(400); // past the reload cooldown

        $result = $this->call($this->find, 'swv', missing_field: 'departure_time', limit: 50);
        $sw2 = $result['routes'][0];
        self::assertSame(['SW2', 'KJFK'], [$sw2['flight_number'], $sw2['arr']], 'cache miss triggered a reload');
        self::assertSame(2, $this->requests('/api/airports') - 1, 'one initial page + one reload of 2 pages');

        $sw5 = $this->call($this->find, 'swv', dep: 'JFK')['routes'][0];
        self::assertSame('KJFK', $sw5['dep']);
        self::assertSame(99, $sw5['arr_id']);
        self::assertTrue($sw5['arr_unresolved']);
        self::assertArrayNotHasKey('arr', $sw5);
        self::assertSame(3, $this->requests('/api/airports'), 'no reload for an unknown ID within the cooldown');
    }

    public function testAirportCacheExpiresAfterTtl(): void
    {
        $this->airportCache->lookup('swv', [10]);
        self::assertSame(2, $this->requests('/api/airports'));

        // A new request within the TTL uses the cache ...
        $this->clock->advance(3600);
        (new AirportCache($this->pdo, $this->client, $this->clock, 86400))->lookup('swv', [10, 20]);
        self::assertSame(2, $this->requests('/api/airports'));

        // ... and one after the TTL reloads it.
        $this->clock->advance(86400);
        $airport = (new AirportCache($this->pdo, $this->client, $this->clock, 86400))->lookup('swv', [30])[30];
        self::assertSame(['id' => 30, 'icao' => 'KJFK', 'iata' => 'JFK', 'name' => 'New York JFK'], $airport);
        self::assertSame(4, $this->requests('/api/airports'));
    }

    public function testCountScansAllPagesOnceThenServesFromCache(): void
    {
        $result = $this->call($this->count, 'swv', missing_field: 'departure_time', breakdown: 'tag');

        self::assertSame(3, $result['matched']);
        self::assertSame(5, $result['scanned']);
        self::assertSame(5, $result['total_routes']);
        self::assertTrue($result['complete']);
        self::assertSame('scan', $result['source']);
        self::assertEquals(['Europe' => 1, 'Longhaul' => 1, '(no tag)' => 1], $result['by_tag']);
        self::assertSame(3, $this->requests('/api/routes'), '5 routes in pages of 2');

        $again = $this->call($this->count, 'swv', dep: 'LKPR', breakdown: 'visibility');
        self::assertSame(['cache', 3], [$again['source'], $again['matched']]);
        self::assertSame(['visible' => 3, 'hidden' => 0], $again['by_visibility']);
        self::assertSame(1, $this->call($this->count, 'swv', tag: 'Europe,Longhaul')['matched'], 'comma = must have all tags');
        self::assertSame(0, $this->call($this->count, 'swv', tag: 'europe')['matched'], 'tags are case-sensitive');
        self::assertSame(3, $this->requests('/api/routes'), 'counts served from the route cache');
    }

    public function testRouteCacheExpiresAndRefreshBypassesIt(): void
    {
        $this->call($this->count, 'swv');
        $this->clock->advance(601);
        self::assertSame('scan', $this->call($this->count, 'swv')['source']);
        self::assertSame(6, $this->requests('/api/routes'), 'expired cache was re-read');

        self::assertSame('scan', $this->call($this->count, 'swv', refresh: true)['source']);
        self::assertSame(9, $this->requests('/api/routes'), 'refresh=true bypasses a fresh cache');
    }

    public function testCountReturnsPartialResultAtTheRateCapAndResumes(): void
    {
        $this->build(3); // token request + 2 route pages fit in one minute

        $partial = $this->call($this->count, 'swv');
        self::assertFalse($partial['complete']);
        self::assertSame(4, $partial['scanned']);
        self::assertSame(4, $partial['matched']);
        self::assertNull($partial['total_routes']);
        self::assertStringContainsString('request cap', $partial['note']);
        self::assertSame(2, $this->requests('/api/routes'), 'stopped instead of exceeding the cap');

        $this->clock->advance(60); // next rate window
        $done = $this->call($this->count, 'swv');
        self::assertTrue($done['complete']);
        self::assertSame(5, $done['total_routes']);
        self::assertSame(3, $this->requests('/api/routes'), 'resumed from the saved cursor, not restarted');
    }

    public function testFindReportsHonestScanFields(): void
    {
        $live = $this->call($this->find, 'swv', limit: 1);
        self::assertSame(['live', false, true, 1], [$live['source'], $live['complete'], $live['has_more'], $live['returned']]);
        self::assertSame(2, $live['scanned'], 'stopped after finding one match beyond the limit');
        self::assertArrayNotHasKey('total_routes', $live);

        $this->call($this->count, 'swv'); // fills the route cache
        $cached = $this->call($this->find, 'swv', missing_field: 'departure_time', limit: 2);
        self::assertSame(['cache', true, 5, 5, 3, 2, true], [$cached['source'], $cached['complete'], $cached['scanned'], $cached['total_routes'], $cached['matched'], $cached['returned'], $cached['has_more']]);
    }

    public function testErrorsAreClearToolErrors(): void
    {
        foreach ([[['nope'], "Unknown airline 'nope'"], [['swv', 'ZZZZ'], "Airport ZZZZ is not in airline 'swv's airport list."], [['swv', 'X'], 'must be a 4-letter ICAO or 3-letter IATA code']] as [$args, $message]) {
            try {
                ($this->find)(...$args);
                self::fail('Expected a tool error');
            } catch (ToolCallException $e) {
                self::assertStringContainsString($message, $e->getMessage());
            }
        }
    }
}
