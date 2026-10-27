<?php

declare(strict_types=1);

namespace VamsysMcp\Tests;

use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use VamsysMcp\AirlineConfig;
use VamsysMcp\Exception\VamsysApiException;
use VamsysMcp\RateLimiter;
use VamsysMcp\Tests\Support\FakeClock;
use VamsysMcp\Tests\Support\FakeHttpClient;
use VamsysMcp\Tests\Support\TestDatabase;
use VamsysMcp\TokenStore;

final class TokenStoreTest extends TestCase
{
    private const string SECRET = 's3cret-value';

    private \PDO $pdo;
    private FakeClock $clock;
    private FakeHttpClient $http;
    private TokenStore $store;
    private AirlineConfig $airline;

    protected function setUp(): void
    {
        $this->pdo = TestDatabase::create();
        $this->clock = new FakeClock();
        $this->http = new FakeHttpClient();
        $psr17 = new Psr17Factory();
        $this->store = new TokenStore(
            $this->pdo,
            $this->http,
            $psr17,
            $psr17,
            new RateLimiter($this->pdo, $this->clock),
            $this->clock,
            'https://vamsys.test/oauth/token',
            refreshMarginSeconds: 300,
        );
        $this->airline = new AirlineConfig('swv', 'Example', '42', self::SECRET, 'kg');
    }

    public function testTokenRequestIsFormUrlencodedNotJson(): void
    {
        $this->http->queue(FakeHttpClient::token());
        $this->store->getToken($this->airline);

        $request = $this->http->requests[0];
        self::assertSame('POST', $request->getMethod());
        self::assertSame('application/x-www-form-urlencoded', $request->getHeaderLine('Content-Type'));
        self::assertSame('application/json', $request->getHeaderLine('Accept'));
        parse_str((string) $request->getBody(), $body);
        self::assertSame(
            ['grant_type' => 'client_credentials', 'client_id' => '42', 'client_secret' => self::SECRET, 'scope' => '*'],
            $body,
        );
        self::assertNull(json_decode((string) $request->getBody()));
    }

    public function testCachesTokenInDatabaseAndReusesIt(): void
    {
        $this->http->queue(FakeHttpClient::token('tok-1', 86400));

        self::assertSame('tok-1', $this->store->getToken($this->airline));
        $this->clock->advance(3600);
        self::assertSame('tok-1', $this->store->getToken($this->airline));
        self::assertCount(1, $this->http->requests);

        $row = $this->pdo->query("SELECT expires_at FROM oauth_token WHERE airline = 'swv'")->fetch();
        self::assertSame('2026-01-02 12:00:00', $row['expires_at']);
    }

    public function testRefreshesShortlyBeforeExpiry(): void
    {
        $this->http->queue(FakeHttpClient::token('tok-1', 604800), FakeHttpClient::token('tok-2', 604800));

        $this->store->getToken($this->airline);
        $this->clock->advance(604800 - 299); // inside the 300 s refresh margin
        self::assertSame('tok-2', $this->store->getToken($this->airline));
        self::assertCount(2, $this->http->requests);
    }

    public function testInvalidateForcesNewToken(): void
    {
        $this->http->queue(FakeHttpClient::token('tok-1'), FakeHttpClient::token('tok-2'));
        $this->store->getToken($this->airline);
        $this->store->invalidate('swv');
        self::assertSame('tok-2', $this->store->getToken($this->airline));
    }

    public function testFailureMessageNeverContainsSecretOrResponseBody(): void
    {
        $this->http->queue(FakeHttpClient::json(['error' => 'invalid_client', 'echo' => self::SECRET], 401));

        try {
            $this->store->getToken($this->airline);
            self::fail('Expected exception');
        } catch (VamsysApiException $e) {
            self::assertStringContainsString('HTTP 401', $e->getMessage());
            self::assertStringNotContainsString(self::SECRET, $e->getMessage());
            self::assertStringNotContainsString('invalid_client', $e->getMessage());
        }
    }
}
