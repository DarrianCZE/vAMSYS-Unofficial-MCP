<?php

declare(strict_types=1);

namespace VamsysMcp\Tests\Support;

use Psr\Clock\ClockInterface;

final class FakeClock implements ClockInterface
{
    private \DateTimeImmutable $now;

    public function __construct(string $now = '2026-01-01 12:00:00')
    {
        $this->now = new \DateTimeImmutable($now, new \DateTimeZone('UTC'));
    }

    public function now(): \DateTimeImmutable
    {
        return $this->now;
    }

    public function advance(float $seconds): void
    {
        $this->now = $this->now->modify(sprintf('+%d microseconds', (int) round($seconds * 1_000_000)));
    }
}
