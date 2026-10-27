<?php

declare(strict_types=1);

namespace VamsysMcp\Tests;

use Mcp\Exception\ToolCallException;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use VamsysMcp\Cache\AirportCache;
use VamsysMcp\Cache\RouteCache;
use VamsysMcp\Cache\ScanLock;
use VamsysMcp\Config;
use VamsysMcp\RateLimiter;
use VamsysMcp\Tests\Support\FakeClock;
use VamsysMcp\Tests\Support\FakeHttpClient;
use VamsysMcp\Tests\Support\TestDatabase;
use VamsysMcp\TokenStore;
use VamsysMcp\Tools\CountRoutesTool;
use VamsysMcp\Tools\FindDuplicatesTool;
use VamsysMcp\Tools\FindRoutesTool;
use VamsysMcp\Tools\GetRouteTool;
use VamsysMcp\Tools\RouteQuery;
use VamsysMcp\VamsysClient;

/**
 * The route tools against a mocked vAMSYS with paged airports and
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
    private GetRouteTool $get;
    private FindDuplicatesTool $duplicates;
    private RouteCache $routeCache;
    private AirportCache $airportCache;
    private VamsysClient $client;
    private \PDO $pdo;

    protected function setUp(): void
    {
        $this->build(60);
    }

    private function build(int $rateCap, ?ScanLock $lock = null, string ...$moreAirlines): void
    {
        $this->http = new FakeHttpClient();
        $this->clock = new FakeClock('2026-01-01 12:00:10');
        $airline = ['client_id' => '1', 'client_secret' => 'x', 'weight_unit' => 'kg', 'rate_cap' => $rateCap];
        $config = Config::fromArray([
            'database' => ['dsn' => 'sqlite::memory:'],
            'vamsys' => ['api_base_url' => 'https://vamsys.test/api', 'token_url' => 'https://vamsys.test/oauth/token', 'page_size' => 2],
            'airlines' => ['swv' => $airline] + array_fill_keys($moreAirlines, $airline),
        ]);
        $this->pdo = TestDatabase::create();
        $psr17 = new Psr17Factory();
        $limiter = new RateLimiter($this->pdo, $this->clock, 0);
        $tokens = new TokenStore($this->pdo, $this->http, $psr17, $psr17, $limiter, $this->clock, $config->tokenUrl);
        $client = $this->client = new VamsysClient($config, $tokens, $limiter, $this->http, $psr17);
        $this->airportCache = new AirportCache($this->pdo, $client, $this->clock, 86400);
        $query = new RouteQuery($this->airportCache, $config);
        $this->routeCache = new RouteCache($this->pdo, $client, $this->clock, 600, 100, 20.0, null, $lock);
        $this->find = new FindRoutesTool($client, $this->routeCache, $query, 10);
        $this->count = new CountRoutesTool($this->routeCache, $query);
        $this->get = new GetRouteTool($client, $query);
        $this->duplicates = new FindDuplicatesTool($this->routeCache, $query);

        $this->http->route(function (RequestInterface $r) {
            parse_str($r->getUri()->getQuery(), $q);
            $path = $r->getUri()->getPath();
            $f = $q['filter'] ?? [];
            if ($path === '/oauth/token') {
                return FakeHttpClient::token();
            }
            if (preg_match('#^/api/routes/(\d+)$#', $path, $m) === 1) {
                $route = array_values(array_filter($this->routes, static fn (array $route): bool => $route['id'] === (int) $m[1]))[0] ?? null;

                return $route === null ? FakeHttpClient::json(['message' => 'Not found.'], 404) : FakeHttpClient::json(['data' => $route]);
            }
            $items = match ($path) {
                '/api/airports' => array_values(array_filter($this->airports, static fn (array $airport): bool =>
                    (!isset($f['icao']) || $airport['icao'] === $f['icao'])
                    && (!isset($f['iata']) || $airport['iata'] === $f['iata']))),
                // Like a case-insensitive database: tag, flight number and callsign filters ignore case.
                '/api/routes' => array_values(array_filter($this->routes, static fn (array $route): bool =>
                    (!isset($f['departure_id']) || (string) $route['departure_id'] === $f['departure_id'])
                    && (!isset($f['arrival_id']) || (string) $route['arrival_id'] === $f['arrival_id'])
                    && (!isset($f['tag']) || array_diff(array_map('strtolower', explode(',', $f['tag'])), array_map('strtolower', $route['tag'])) === [])
                    && (!isset($f['flight_number']) || strcasecmp($route['flight_number'], $f['flight_number']) === 0)
                    && (!isset($f['callsign']) || strcasecmp($route['callsign'] ?? '', $f['callsign']) === 0)
                    && (!isset($f['hidden']) || $route['hidden'] === ($f['hidden'] === '1')))),
            };
            $offset = (int) ($q['page']['cursor'] ?? 0);
            $size = (int) $q['page']['size'];
            $next = $offset + $size < count($items) ? (string) ($offset + $size) : null;

            return FakeHttpClient::json(['data' => array_slice($items, $offset, $size), 'meta' => ['next_cursor' => $next]]);
        });
    }

    private function requests(string $path, ?string $queryPart = null): int
    {
        return count(array_filter($this->http->requests, static fn (RequestInterface $r): bool => $r->getUri()->getPath() === $path
            && ($queryPart === null || str_contains(urldecode($r->getUri()->getQuery()), $queryPart))));
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
        self::assertSame(1, $this->requests('/api/airports', 'filter[iata]=PRG'), 'cold cache: the filter code cost one filtered request');
        self::assertSame(3, $this->requests('/api/airports'), '+ the airport list read once (2 pages) for the output codes');

        $this->call($this->find, 'swv', dep: 'LKPR', arr: 'egll');
        self::assertSame(3, $this->requests('/api/airports'), 'ICAO/IATA filters resolved from the fresh cache, no extra calls');
    }

    public function testFilterCodesNeverReloadTheAirportList(): void
    {
        // Cold cache: two filtered lookups, one filtered /routes page, no airport list, no full route scan.
        $result = $this->call($this->count, 'swv', dep: 'LKPR', arr: 'LHR');
        self::assertSame(['live', true, 2, 2, null], [$result['source'], $result['complete'], $result['matched'], $result['scanned'], $result['total_routes']]);
        self::assertSame(['dep' => 'LKPR', 'arr' => 'EGLL'], $result['filters']);
        self::assertSame(2, $this->requests('/api/airports'));
        self::assertSame(2, $this->requests('/api/airports', 'filter['));
        self::assertSame(1, $this->requests('/api/routes'));
        self::assertSame(1, $this->requests('/api/routes', 'filter[departure_id]=10&filter[arrival_id]=20'));

        // A stale list is not reloaded for a filter either.
        $this->airportCache->reload('swv');
        $this->clock->advance(86401);
        $this->call($this->count, 'swv', dep: 'JFK');
        self::assertSame(5, $this->requests('/api/airports'), '2 lookups + 2-page list + 1 lookup');

        self::assertSame(0, $this->call($this->count, 'swv', arr: 'LKPR', missing_field: 'departure_time', tag: 'Longhaul')['matched'], 'local filters still apply');
    }

    public function testNarrowCountIgnoresAnUnfinishedFullScanAndUsesAFreshOne(): void
    {
        $this->build(3); // token + 2 pages
        self::assertFalse($this->call($this->count, 'swv')['complete']);

        $this->clock->advance(60);
        $narrow = $this->call($this->count, 'swv', dep: 'LKPR');
        self::assertSame(['live', true, 3], [$narrow['source'], $narrow['complete'], $narrow['matched']]);

        $this->clock->advance(60);
        self::assertTrue($this->call($this->count, 'swv')['complete'], 'the full scan resumed later as before');
        self::assertSame(['cache', 3], [($c = $this->call($this->count, 'swv', dep: 'LKPR'))['source'], $c['matched']]);
        self::assertSame('live', $this->call($this->count, 'swv', dep: 'LKPR', refresh: true)['source'], 'refresh=true skips the cache');
    }

    public function testFindWithRefreshAndAirportFilterSearchesLive(): void
    {
        $this->call($this->count, 'swv'); // fills the route cache
        $before = $this->requests('/api/routes');

        $result = $this->call($this->find, 'swv', dep: 'LKPR', refresh: true);
        self::assertSame(['live', true, 3], [$result['source'], $result['complete'], $result['matched']]);
        self::assertSame($before + 2, $this->requests('/api/routes'), '3 matches in pages of 2, not a full re-read');
    }

    public function testFilteredCountPausesAtTheCapSaysWhenToRetryAndResumes(): void
    {
        $this->build(3); // token + 1 lookup + 1 route page
        $result = $this->call($this->count, 'swv', dep: 'LKPR');

        self::assertSame(['live', false, 2, null], [$result['source'], $result['complete'], $result['scanned'], $result['total_routes']]);
        self::assertSame([true, 50], [$result['resumable'], $result['retry_after_seconds']], 'clock is 10 s into the minute');
        self::assertArrayNotHasKey('progress', $result, 'no earlier complete scan to estimate from');
        self::assertStringContainsString('Call again in 50 seconds with the same arguments to continue', $result['note']);

        $this->clock->advance(60);
        $done = $this->call($this->count, 'swv', dep: 'LKPR');
        self::assertSame(['live', true, 3], [$done['source'], $done['complete'], $done['matched']]);
        self::assertSame(2, $this->requests('/api/routes'), 'continued from the saved cursor');
    }

    public function testProgressIsEstimatedFromTheLastCompleteScanWhichKeepsServing(): void
    {
        $this->build(3);
        $this->call($this->count, 'swv'); // token + 2 pages, paused
        $this->clock->advance(60);
        self::assertTrue($this->call($this->count, 'swv')['complete'], '1 page: 5 routes, 2 requests left this minute');

        // A refresh scan pauses after 2 pages; the estimate uses the 5 routes of the last complete set.
        $partial = $this->call($this->count, 'swv', refresh: true);
        self::assertSame([false, 0.8], [$partial['complete'], $partial['progress']]);
        // Meanwhile the previous complete set still answers normal calls.
        self::assertSame(['cache', true, 5], [($c = $this->call($this->count, 'swv'))['source'], $c['complete'], $c['total_routes']]);
    }

    public function testASecondCallWaitsForTheScanLockAndReportsTheOthersProgress(): void
    {
        $lock = new ScanLock(TestDatabase::create());
        $this->build(60, $lock);
        $lock->acquire('scan:swv:', 0); // another call is scanning the full set

        $busy = $this->call($this->count, 'swv', missing_field: 'remarks');
        self::assertSame([false, 'scan', 5, 0], [$busy['complete'], $busy['source'], $busy['retry_after_seconds'], $busy['scanned']]);
        self::assertStringContainsString('another call is reading the same routes', $busy['note']);
        self::assertSame(0, $this->requests('/api/routes'), 'no duplicate scan');

        $lock->release('scan:swv:');
        self::assertTrue($this->call($this->count, 'swv', missing_field: 'remarks')['complete']);
    }

    public function testMoreFiltersAreSentToVamsysAndRecheckedLocally(): void
    {
        $sw = $this->call($this->count, 'swv', flight_number: 'sw3');
        self::assertSame(['live', 1, ['flight_number' => 'sw3']], [$sw['source'], $sw['matched'], $sw['filters']]);
        self::assertSame(1, $this->requests('/api/routes', 'filter[flight_number]=sw3'));

        // vAMSYS here matches tags case-insensitively; the local re-check keeps tags case-sensitive.
        self::assertSame(0, $this->call($this->count, 'swv', tag: 'europe')['matched']);
        self::assertSame(3, $this->call($this->count, 'swv', tag: 'Europe')['matched']);
        self::assertSame(2, $this->requests('/api/routes', 'filter[tag]=Europe'), '3 matches in pages of 2');

        $hidden = $this->call($this->count, 'swv', hidden: true, breakdown: 'visibility');
        self::assertSame([1, ['visible' => 0, 'hidden' => 1]], [$hidden['matched'], $hidden['by_visibility']]);
        self::assertSame(1, $this->requests('/api/routes', 'filter[hidden]=1'));
        self::assertSame(0, $this->requests('/api/routes', 'filter[missing'), 'nothing vAMSYS cannot filter is sent');
    }

    public function testBothDirections(): void
    {
        $count = $this->call($this->count, 'swv', dep: 'LKPR', arr: 'EGLL', both_directions: true);
        self::assertSame([3, ['LKPR-EGLL' => 2, 'EGLL-LKPR' => 1], true], [$count['matched'], $count['by_direction'], $count['filters']['both_directions']]);
        self::assertSame(2, $this->requests('/api/routes'), 'one filtered request per direction');

        $find = $this->call($this->find, 'swv', dep: 'PRG', arr: 'LHR', both_directions: true);
        self::assertSame(['cache', 3, [1, 3, 4]], [$find['source'], $find['matched'], array_column($find['routes'], 'id')]);
        self::assertSame(2, $this->requests('/api/routes'), 'both direction sets reused from the cache');

        $this->expectException(ToolCallException::class);
        ($this->count)('swv', dep: 'LKPR', both_directions: true);
    }

    public function testFindPagesWithOffset(): void
    {
        $first = $this->call($this->find, 'swv', dep: 'LKPR', limit: 2);
        self::assertSame([[1, 2], true, 2, 3], [array_column($first['routes'], 'id'), $first['has_more'], $first['next_offset'], $first['matched']]);
        $second = $this->call($this->find, 'swv', dep: 'LKPR', limit: 2, offset: 2);
        self::assertSame([[4], false], [array_column($second['routes'], 'id'), $second['has_more']]);
        self::assertArrayNotHasKey('next_offset', $second);

        // Live paging (no filter vAMSYS can narrow) skips the earlier matches.
        $live = $this->call($this->find, 'swv', missing_field: 'departure_time', limit: 1, offset: 1);
        self::assertSame(['live', [3], true, 2], [$live['source'], array_column($live['routes'], 'id'), $live['has_more'], $live['next_offset']]);
    }

    public function testGetRoute(): void
    {
        $this->routes[0] += ['route' => 'DCT', 'internal_remarks' => 'secret', 'simbrief_options' => ['x' => 1], 'service_days' => ['monday'], 'fleet_ids' => [7]];
        $route = $this->call($this->get, 1)['route'];
        self::assertSame(['id' => 1, 'flight_number' => 'SW1', 'dep' => 'LKPR', 'dep_iata' => 'PRG', 'arr' => 'EGLL', 'arr_iata' => 'LHR', 'dep_time' => '08:00:00', 'routing' => 'DCT', 'tags' => ['Europe'], 'service_days' => ['monday'], 'fleet_ids' => [7]], $route);

        try {
            ($this->get)(42);
            self::fail('Expected a tool error');
        } catch (ToolCallException $e) {
            self::assertSame("Route 42 does not exist in airline 'swv'.", $e->getMessage());
        }
    }

    public function testScanStopsBeforeTheNextPageWouldOverrunTheTimeBudget(): void
    {
        $now = 0.0;
        $pageSeconds = 6.0;
        $inner = \Closure::bind(fn () => $this->router, $this->http, FakeHttpClient::class)();
        $this->http->route(function (RequestInterface $r) use ($inner, &$now, $pageSeconds) {
            $now += $pageSeconds; // every request takes 6 s

            return $inner($r);
        });
        $cache = new RouteCache($this->pdo, $this->client, $this->clock, 600, 100, 15.0, static function () use (&$now): float {
            return $now;
        });

        $set = $cache->scan('swv');
        // Page 1 (with the token request) took 12 s; another 12 s would end after the 15 s budget.
        self::assertSame([false, 'time_budget', 2], [$set->complete, $set->stopReason, count($set->routes)]);
        self::assertSame(12.0, $now);

        $set = $cache->scan('swv'); // a new call continues: the 2 remaining 6 s pages fit
        self::assertSame([true, 5], [$set->complete, count($set->routes)]);
        self::assertSame(24.0, $now);
    }

    public function testFindDuplicates(): void
    {
        $this->routes[] = ['id' => 6, 'flight_number' => 'sw1', 'departure_id' => 10, 'arrival_id' => 20, 'departure_time' => '08:00:00', 'tag' => [], 'hidden' => false];
        $this->routes[] = ['id' => 7, 'flight_number' => 'SW1', 'departure_id' => 20, 'arrival_id' => 10, 'departure_time' => '18:00:00', 'tag' => [], 'hidden' => false];

        $schedule = $this->call($this->duplicates);
        self::assertSame([true, 1, 2], [$schedule['complete'], $schedule['groups_found'], $schedule['duplicate_routes']]);
        self::assertSame(['flight_number' => 'SW1', 'dep' => 'LKPR', 'dep_iata' => 'PRG', 'arr' => 'EGLL', 'arr_iata' => 'LHR', 'dep_time' => '08:00:00', 'route_ids' => [1, 6]], $schedule['groups'][0]);

        $byNumber = $this->call($this->duplicates, by: 'flight_number');
        self::assertSame([1, 3, [1, 6, 7]], [$byNumber['groups_found'], $byNumber['groups'][0]['count'], array_column($byNumber['groups'][0]['routes'], 'id')]);
        self::assertSame(0, $this->call($this->duplicates, dep: 'JFK')['groups_found']);
    }

    public function testBreakdownByAirport(): void
    {
        $result = $this->call($this->count, breakdown: 'dep');
        self::assertSame(['LKPR' => 3, 'EGLL' => 1, 'KJFK' => 1], $result['by_dep']);
        self::assertSame(['EGLL' => 2, 'KJFK' => 1, 'LKPR' => 1, '#99' => 1], $this->call($this->count, breakdown: 'arr')['by_arr']);
    }

    public function testAirlineMayBeOmittedOnlyWithOneAirline(): void
    {
        self::assertSame('swv', $this->call($this->count)['airline']);

        $this->build(60, null, 'neo');
        try {
            ($this->count)();
            self::fail('Expected a tool error');
        } catch (ToolCallException $e) {
            self::assertSame('Several airlines are configured, so the airline argument is required: swv, neo.', $e->getMessage());
        }
        self::assertSame('neo', $this->call($this->count, 'neo')['airline']);
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
        self::assertSame(50, $partial['retry_after_seconds']);
        self::assertStringContainsString('Call again in 50 seconds with the same arguments to continue', $partial['note']);
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
