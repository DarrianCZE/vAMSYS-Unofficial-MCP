<?php

declare(strict_types=1);

namespace VamsysMcp\Update;

use VamsysMcp\Install\FtpCredentials;

/**
 * Writes installation files over FTP (explicit FTPS by default) for hosts
 * where PHP cannot write the files itself. Needs the PHP ftp extension.
 */
final class FtpFileWriter implements FileWriter
{
    /** @var \FTP\Connection|null */
    private ?\FTP\Connection $conn = null;
    /** @var array<string, true> */
    private array $knownDirs = [];

    public function __construct(private readonly FtpCredentials $credentials)
    {
    }

    /**
     * Check the login and that the FTP path really is this installation: write
     * a probe file over FTP and look for it on the local filesystem.
     */
    public function probe(string $localRoot): void
    {
        $name = '.vamsys-ftp-probe-' . bin2hex(random_bytes(6));
        $tmp = tempnam(sys_get_temp_dir(), 'vmcp');
        file_put_contents($tmp, 'probe');
        try {
            $this->upload($name, $tmp);
            clearstatcache();
            $seen = is_file("{$localRoot}/{$name}");
            @ftp_delete($this->conn(), $this->remote($name));
        } finally {
            @unlink($tmp);
        }
        if (!$seen) {
            throw new UpdateException("FTP login works, but '{$this->credentials->path}' is not this installation's directory. Set the FTP path to the folder that contains src/ and vendor/.");
        }
    }

    public function put(string $relativePath, string $sourceFile): void
    {
        $this->upload($relativePath, $sourceFile);
    }

    public function delete(string $relativePath): void
    {
        $remote = $this->remote($relativePath);
        if (ftp_size($this->conn(), $remote) >= 0 && !@ftp_delete($this->conn(), $remote)) {
            throw new UpdateException("FTP: cannot delete {$relativePath}.");
        }
    }

    public function close(): void
    {
        if ($this->conn !== null) {
            @ftp_close($this->conn);
            $this->conn = null;
        }
    }

    private function upload(string $relativePath, string $sourceFile): void
    {
        $remote = $this->remote($relativePath);
        $this->ensureRemoteDir(dirname($remote));
        $tmp = dirname($remote) . '/.' . basename($remote) . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if (!@ftp_put($this->conn(), $tmp, $sourceFile, FTP_BINARY)) {
            throw new UpdateException("FTP: cannot upload {$relativePath}.");
        }
        // Many FTP servers refuse to rename onto an existing file.
        @ftp_delete($this->conn(), $remote);
        if (!@ftp_rename($this->conn(), $tmp, $remote)) {
            @ftp_delete($this->conn(), $tmp);
            throw new UpdateException("FTP: cannot replace {$relativePath}.");
        }
    }

    private function ensureRemoteDir(string $dir): void
    {
        if ($dir === '/' || $dir === '' || isset($this->knownDirs[$dir])) {
            return;
        }
        if (!@ftp_chdir($this->conn(), $dir)) {
            $this->ensureRemoteDir(dirname($dir));
            if (!@ftp_mkdir($this->conn(), $dir)) {
                throw new UpdateException("FTP: cannot create directory {$dir}.");
            }
        }
        $this->knownDirs[$dir] = true;
    }

    private function remote(string $relativePath): string
    {
        return rtrim($this->credentials->path, '/') . '/' . $relativePath;
    }

    private function conn(): \FTP\Connection
    {
        if ($this->conn !== null) {
            return $this->conn;
        }
        if (!function_exists('ftp_connect')) {
            throw new UpdateException('The PHP ftp extension is not available, so updates over FTP are not possible on this host.');
        }
        $c = $this->credentials;
        $conn = $c->tls ? @ftp_ssl_connect($c->host, $c->port, 20) : @ftp_connect($c->host, $c->port, 20);
        if ($conn === false) {
            throw new UpdateException("FTP: cannot connect to {$c->host}:{$c->port}" . ($c->tls ? ' with TLS' : '') . '.');
        }
        if (!@ftp_login($conn, $c->username, $c->password)) {
            @ftp_close($conn);
            throw new UpdateException('FTP: login failed; check username and password.');
        }
        ftp_pasv($conn, true);

        return $this->conn = $conn;
    }
}
