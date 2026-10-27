<?php

declare(strict_types=1);

namespace VamsysMcp;

/**
 * Well-known locations inside an installation. Everything under var/ and
 * config/config.php belongs to the operator and is never touched by updates.
 */
final class Paths
{
    public readonly string $root;

    public function __construct(?string $root = null)
    {
        $this->root = rtrim(str_replace('\\', '/', $root ?? dirname(__DIR__)), '/');
    }

    /** $VAMSYS_MCP_CONFIG, else <root>/config/config.php. */
    public function configFile(): string
    {
        $env = getenv('VAMSYS_MCP_CONFIG');

        return is_string($env) && $env !== '' ? $env : $this->root . '/config/config.php';
    }

    public function var(string $sub = ''): string
    {
        return $this->root . '/var' . ($sub === '' ? '' : '/' . ltrim($sub, '/'));
    }

    public function migrationsDir(): string
    {
        return $this->root . '/migrations';
    }

    public function maintenanceFlag(): string
    {
        return $this->var('maintenance');
    }

    public function updateDir(string $sub = ''): string
    {
        return $this->var('update' . ($sub === '' ? '' : '/' . ltrim($sub, '/')));
    }

    public function ensureVarDir(string $sub = ''): string
    {
        $dir = $this->var($sub);
        if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new \RuntimeException("Cannot create directory {$dir}; make var/ writable by PHP.");
        }

        return $dir;
    }
}
