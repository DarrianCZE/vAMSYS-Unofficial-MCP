<?php

declare(strict_types=1);

namespace VamsysMcp;

use Psr\Clock\ClockInterface;

/**
 * Optional audit log of outgoing vAMSYS calls (enable with 'audit_log' => true).
 * Records the path only, never query strings, headers or bodies.
 */
final class CallLog
{
    public function __construct(
        private readonly \PDO $pdo,
        private readonly ClockInterface $clock,
    ) {
    }

    public function record(string $airline, string $method, string $path, int $status, int $durationMs): void
    {
        try {
            $this->pdo->prepare(
                'INSERT INTO call_log (airline, method, path, status, duration_ms, created_at)
                 VALUES (:airline, :method, :path, :status, :duration, :created)',
            )->execute([
                'airline' => $airline,
                'method' => $method,
                'path' => substr($path, 0, 255),
                'status' => $status,
                'duration' => $durationMs,
                'created' => $this->clock->now()->format('Y-m-d H:i:s'),
            ]);
        } catch (\PDOException) {
            // Auditing must never break a read; the DB error itself may contain the DSN.
            error_log('vamsys-mcp: could not write call_log row');
        }
    }
}
