<?php

declare(strict_types=1);

namespace VamsysMcp\Tests\Support;

use VamsysMcp\Update\Fs;

final class TempDir
{
    public static function create(): string
    {
        $dir = str_replace('\\', '/', sys_get_temp_dir()) . '/vamsys-mcp-test-' . bin2hex(random_bytes(6));
        mkdir($dir, 0700, true);

        return $dir;
    }

    /**
     * @param array<string, string> $files relative path => content
     */
    public static function write(string $root, array $files): void
    {
        foreach ($files as $path => $content) {
            Fs::ensureDir(dirname("{$root}/{$path}"));
            file_put_contents("{$root}/{$path}", $content);
        }
    }

    public static function remove(string $dir): void
    {
        Fs::removeDir($dir);
    }
}
