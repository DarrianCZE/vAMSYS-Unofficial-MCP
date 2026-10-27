<?php

declare(strict_types=1);

namespace VamsysMcp\Install;

use VamsysMcp\Exception\ConfigException;

/**
 * Reads and writes config/config.php as plain PHP (`return [...]`).
 *
 * Files written by the installer carry a marker line. A hand-written config
 * (e.g. one using getenv()) has no marker and is never overwritten, because
 * rewriting it would replace those expressions with literal values.
 */
final class ConfigFile
{
    public const string MARKER = '// managed-by: vamsys-mcp-installer';

    public function __construct(public readonly string $path)
    {
    }

    public function exists(): bool
    {
        return is_file($this->path);
    }

    public function isManaged(): bool
    {
        return $this->exists() && str_contains((string) file_get_contents($this->path), self::MARKER);
    }

    /**
     * @return array<string, mixed>
     */
    public function load(): array
    {
        if (!$this->exists()) {
            return [];
        }
        $raw = require $this->path;
        if (!is_array($raw)) {
            throw new ConfigException('Config file must return an array.');
        }

        return $raw;
    }

    /**
     * Atomically replace the config file and restrict it to the owner.
     *
     * @param array<string, mixed> $raw
     */
    public function write(array $raw): void
    {
        if ($this->exists() && !$this->isManaged()) {
            throw new ConfigException(
                'config.php was written by hand (no installer marker); edit it manually or delete it to let the installer manage it.',
            );
        }

        $dir = dirname($this->path);
        if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new ConfigException("Cannot create config directory {$dir}.");
        }

        $php = "<?php\n\n" . self::MARKER . "\n"
            . "// Written by the vAMSYS MCP installer / admin UI. Contains secrets: keep it outside the web root, chmod 600.\n"
            . "// You may edit values by hand; keep plain values (no expressions) or remove the marker line above.\n\n"
            . "declare(strict_types=1);\n\n"
            . 'return ' . var_export($raw, true) . ";\n";

        $tmp = $dir . '/.config.' . bin2hex(random_bytes(6)) . '.tmp';
        if (file_put_contents($tmp, $php, LOCK_EX) === false) {
            throw new ConfigException("Cannot write config file in {$dir}; check permissions.");
        }
        @chmod($tmp, 0600);
        if (!rename($tmp, $this->path)) {
            @unlink($tmp);
            throw new ConfigException("Cannot replace {$this->path}; check permissions.");
        }
        if (function_exists('opcache_invalidate')) {
            @opcache_invalidate($this->path, true);
        }
    }
}
