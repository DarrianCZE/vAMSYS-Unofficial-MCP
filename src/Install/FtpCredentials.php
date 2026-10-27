<?php

declare(strict_types=1);

namespace VamsysMcp\Install;

use VamsysMcp\Exception\ConfigException;

/**
 * FTP login used by the updater when PHP cannot write the installation files
 * itself. Stored only encrypted (EncryptedSettings::FTP).
 */
final class FtpCredentials
{
    public function __construct(
        public readonly string $host,
        public readonly int $port,
        public readonly string $username,
        #[\SensitiveParameter] public readonly string $password,
        /** FTP path of the installation directory (the folder containing src/ and vendor/). */
        public readonly string $path,
        public readonly bool $tls = true,
    ) {
        if (preg_match('/^[a-z0-9.-]+$/i', $host) !== 1) {
            throw new ConfigException('FTP host must be a host name or IP address.');
        }
        if ($port < 1 || $port > 65535 || $username === '' || $password === '') {
            throw new ConfigException('FTP port, username and password are required.');
        }
        if (!str_starts_with($path, '/') || str_contains($path, '..')) {
            throw new ConfigException("FTP path must be absolute as seen by the FTP server, e.g. '/vamsys-mcp'.");
        }
    }

    /**
     * Build from form/CLI input; an empty password keeps the one from $existing.
     *
     * @param array<string, mixed> $in
     */
    public static function fromInput(array $in, ?self $existing = null): self
    {
        $password = (string) ($in['password'] ?? '');

        return new self(
            host: trim((string) ($in['host'] ?? '')),
            port: (int) ($in['port'] ?? 21),
            username: trim((string) ($in['username'] ?? '')),
            password: $password !== '' ? $password : ($existing?->password ?? ''),
            path: '/' . trim(trim((string) ($in['path'] ?? '')), '/'),
            tls: (bool) ($in['tls'] ?? false),
        );
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self((string) $data['host'], (int) $data['port'], (string) $data['username'], (string) $data['password'], (string) $data['path'], (bool) $data['tls']);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return ['host' => $this->host, 'port' => $this->port, 'username' => $this->username, 'password' => $this->password, 'path' => $this->path, 'tls' => $this->tls];
    }

    /**
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return ['password' => '***'] + $this->toArray();
    }
}
