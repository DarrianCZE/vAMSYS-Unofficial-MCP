<?php

declare(strict_types=1);

namespace VamsysMcp\Tests;

use PHPUnit\Framework\TestCase;
use VamsysMcp\Exception\RateLimitExceededException;
use VamsysMcp\RateLimiter;
use VamsysMcp\Tests\Support\FakeClock;
use VamsysMcp\Tests\Support\TestDatabase;

final class RateLimiterTest extends TestCase
{
    private \PDO $pdo;
    private FakeClock $clock;
    /** @var list<float> */
    private array $sleeps = [];

    protected function setUp(): void
    {
        $this->pdo = TestDatabase::create();
        $this->clock = new FakeClock('2026-01-01 12:00:10');
        $this->sleeps = [];
    }

    private function limiter(int $maxWait = 0): RateLimiter
    {
        return new RateLimiter($this->pdo, $this->clock, $maxWait, function (float $s): void {
            $this->sleeps[] = $s;
            $this->clock->advance($s);
        });
    }

    public function testAllowsRequestsUpToTheCap(): void
    {
        $limiter = $this->limiter();
        for ($i = 0; $i < 3; ++$i) {
            $limiter->acquire('swv', 3);
        }
        self::assertSame(3, (int) $this->pdo->query("SELECT req_count FROM rate_window WHERE airline = 'swv'")->fetchColumn());
    }

    public function testThrowsWithClearMessageWhenCapReachedAndNoWaitAllowed(): void
    {
        $limiter = $this->limiter(maxWait: 0);
        $limiter->acquire('swv', 2);
        $limiter->acquire('swv', 2);

        $this->expectException(RateLimitExceededException::class);
        $this->expectExceptionMessageMatches("/cap of 2 requests\/minute for airline 'swv'.*about 50 seconds/");
        $limiter->acquire('swv', 2);
    }

    public function testWaitsForNextWindowWhenWithinMaxWait(): void
    {
        $limiter = $this->limiter(maxWait: 60);
        $limiter->acquire('swv', 1);
        $limiter->acquire('swv', 1); // must sleep ~50s into the next window

        self::assertCount(1, $this->sleeps);
        self::assertEqualsWithDelta(50.05, $this->sleeps[0], 0.01);
        $row = $this->pdo->query("SELECT window_start, req_count FROM rate_window WHERE airline = 'swv'")->fetch();
        self::assertSame('2026-01-01 12:01:00', $row['window_start']);
        self::assertSame(1, (int) $row['req_count']);
    }

    public function testCounterResetsInNewWindow(): void
    {
        $limiter = $this->limiter();
        $limiter->acquire('swv', 1);
        $this->clock->advance(60);
        $limiter->acquire('swv', 1);
        self::assertSame(1, (int) $this->pdo->query("SELECT req_count FROM rate_window WHERE airline = 'swv'")->fetchColumn());
    }

    public function testAirlinesHaveSeparateCounters(): void
    {
        $limiter = $this->limiter();
        $limiter->acquire('swv', 1);
        $limiter->acquire('abc', 1);

        $this->expectException(RateLimitExceededException::class);
        $limiter->acquire('swv', 1);
    }
}
