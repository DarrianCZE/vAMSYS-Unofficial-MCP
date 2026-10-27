<?php

declare(strict_types=1);

namespace VamsysMcp\Tests;

use Mcp\Exception\ToolCallException;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use VamsysMcp\Config;
use VamsysMcp\RateLimiter;
use VamsysMcp\Tests\Support\FakeClock;
use VamsysMcp\Tests\Support\FakeHttpClient;
use VamsysMcp\Tests\Support\TestDatabase;
use VamsysMcp\TokenStore;
use VamsysMcp\Tools\FindRoutesTool;
use VamsysMcp\VamsysClient;

final class FindRoutesToolTest extends TestCase
{
    private FakeHttpClient $http;
    private FindRoutesTool $tool;

    protected function setUp(): void
    {
        $this->http = new FakeHttpClient();
        $config = Config::fromArray([
            'database' => ['dsn' => 'sqlite::memory:'],
            'vamsys' => ['api_base_url' => 'https://vamsys.test/api', 'token_url' => 'https://vamsys.test/oauth/token'],
            'airlines' => ['swv' => ['client_id' => '1', 'client_secret' => 'x', 'weight_unit' => 'kg']],
        ]);
        $pdo = TestDatabase::create();
        $clock = new FakeClock();
        $psr17 = new Psr17Factory();
        $limiter = new RateLimiter($pdo, $clock, 0);
        $tokens = new TokenStore($pdo, $this->http, $psr17, $psr17, $limiter, $clock, $config->tokenUrl);
        $this->tool = new FindRoutesTool(new VamsysClient($config, $tokens, $limiter, $this->http, $psr17));

        $routes = [
            ['id' => 1, 'flight_number' => 'SW1', 'departure_id' => 10, 'arrival_id' => 20, 'departure_time' => '08:00:00', 'tag' => ['Domestic']],
            ['id' => 2, 'flight_number' => 'SW2', 'departure_id' => 10, 'arrival_id' => 30, 'departure_time' => null, 'tag' => []],
            ['id' => 3, 'flight_number' => 'SW3', 'departure_id' => 10, 'arrival_id' => 40, 'departure_time' => null, 'tag' => null],
        ];
        $this->http->route(static function (RequestInterface $r) use ($routes) {
            parse_str($r->getUri()->getQuery(), $q);

            return match ($r->getUri()->getPath()) {
                '/oauth/token' => FakeHttpClient::token(),
                '/api/airports' => FakeHttpClient::json(['data' => ($q['filter']['icao'] ?? '') === 'LKPR' ? [['id' => 10, 'icao' => 'LKPR']] : []]),
                '/api/routes' => FakeHttpClient::json(['data' => $routes, 'meta' => ['next_cursor' => null]]),
            };
        });
    }

    public function testResolvesIcaoFiltersMissingFieldAndReturnsCompactSummary(): void
    {
        $result = ($this->tool)('swv', dep: 'lkpr', missing_field: 'departure_time')->structuredContent;

        self::assertSame(2, $result['returned']);
        self::assertFalse($result['has_more']);
        self::assertSame(
            [['id' => 2, 'flight_number' => 'SW2', 'dep' => 'LKPR', 'arr_id' => 30], ['id' => 3, 'flight_number' => 'SW3', 'dep' => 'LKPR', 'arr_id' => 40]],
            $result['routes'],
        );

        $routesRequest = array_values(array_filter($this->http->requests, static fn ($r) => $r->getUri()->getPath() === '/api/routes'))[0];
        parse_str($routesRequest->getUri()->getQuery(), $q);
        self::assertSame('10', $q['filter']['departure_id']);
        self::assertSame('kg', $q['weight_unit']);
    }

    public function testLimitSetsHasMore(): void
    {
        $result = ($this->tool)('swv', limit: 1)->structuredContent;
        self::assertSame(1, $result['returned']);
        self::assertTrue($result['has_more']);
    }

    public function testUnknownAirportGivesClearToolError(): void
    {
        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage("Airport 'EGLL' is not in airline 'swv's airport list.");
        ($this->tool)('swv', dep: 'EGLL');
    }

    public function testUnknownAirlineGivesClearToolError(): void
    {
        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessageMatches("/Unknown airline 'nope'. Configured airlines: swv/");
        ($this->tool)('nope');
    }
}
