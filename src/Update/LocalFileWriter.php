<?php

declare(strict_types=1);

namespace VamsysMcp\Update;

final class LocalFileWriter implements FileWriter
{
    public function __construct(private readonly string $root)
    {
    }

    /**
     * Whether PHP can write every target path itself (common on shared hosting
     * where PHP runs as the account owner). If not, the updater uses FTP.
     *
     * @param list<string> $paths
     */
    public static function canWrite(string $root, array $paths): bool
    {
        $probe = $root . '/.vamsys-write-probe-' . bin2hex(random_bytes(4));
        if (@file_put_contents($probe, 'x') === false) {
            return false;
        }
        @unlink($probe);

        foreach ($paths as $path) {
            $target = "{$root}/{$path}";
            if (file_exists($target)) {
                if (!is_writable($target) || !is_writable(dirname($target))) {
                    return false;
                }
                continue;
            }
            $dir = dirname($target);
            while (!is_dir($dir) && strlen($dir) > strlen($root)) {
                $dir = dirname($dir);
            }
            if (!is_writable($dir)) {
                return false;
            }
        }

        return true;
    }

    public function put(string $relativePath, string $sourceFile): void
    {
        Fs::copyAtomic($sourceFile, "{$this->root}/{$relativePath}");
    }

    public function delete(string $relativePath): void
    {
        $target = "{$this->root}/{$relativePath}";
        if (is_file($target) && !@unlink($target)) {
            throw new UpdateException("Cannot delete {$relativePath}.");
        }
    }

    public function close(): void
    {
    }
}
