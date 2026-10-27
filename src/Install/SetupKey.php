<?php

declare(strict_types=1);

namespace VamsysMcp\Install;

/**
 * One-time key that unlocks the web installer (and admin-password reset).
 *
 * The key is written to var/setup-key.txt, outside the web root. Only someone
 * who can read the server's files (FTP, hosting file manager, SSH) can unlock
 * the installer, so a stranger who finds setup.php first cannot take over.
 */
final class SetupKey
{
    public function __construct(private readonly string $file)
    {
    }

    public function path(): string
    {
        return $this->file;
    }

    /** Create the key file if it does not exist yet. */
    public function ensure(): void
    {
        if (is_file($this->file)) {
            return;
        }
        $dir = dirname($this->file);
        if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new \RuntimeException("Cannot create {$dir}; make the var/ directory writable by PHP.");
        }
        $key = bin2hex(random_bytes(16));
        if (file_put_contents($this->file, $key . "\n", LOCK_EX) === false) {
            throw new \RuntimeException("Cannot write {$this->file}; make the var/ directory writable by PHP.");
        }
        @chmod($this->file, 0600);
    }

    public function verify(#[\SensitiveParameter] string $candidate): bool
    {
        $key = is_file($this->file) ? trim((string) file_get_contents($this->file)) : '';

        return $key !== '' && hash_equals($key, trim($candidate));
    }

    public function consume(): void
    {
        if (is_file($this->file)) {
            @unlink($this->file);
        }
    }
}
