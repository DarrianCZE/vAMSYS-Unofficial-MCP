<?php

declare(strict_types=1);

namespace VamsysMcp\Update;

/** Small local-filesystem helpers for the updater (var/ is always PHP-writable). */
final class Fs
{
    public static function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($it as $file) {
            /** @var \SplFileInfo $file */
            $file->isDir() && !$file->isLink() ? @rmdir($file->getPathname()) : @unlink($file->getPathname());
        }
        @rmdir($dir);
    }

    public static function ensureDir(string $dir): void
    {
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new UpdateException("Cannot create directory {$dir}.");
        }
    }

    /** Copy via a temporary file and rename, so readers never see half a file. */
    public static function copyAtomic(string $from, string $to): void
    {
        self::ensureDir(dirname($to));
        $tmp = dirname($to) . '/.' . basename($to) . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if (!copy($from, $tmp)) {
            throw new UpdateException("Cannot write {$to}.");
        }
        if (!@rename($tmp, $to)) {
            @unlink($tmp);
            throw new UpdateException("Cannot replace {$to}.");
        }
    }
}
