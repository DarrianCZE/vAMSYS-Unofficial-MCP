<?php

declare(strict_types=1);

namespace VamsysMcp\Update;

/**
 * The set of files a release owns. The updater only ever writes or deletes
 * these; config/config.php, var/ and anything else in the installation
 * directory belong to the operator and are never touched.
 */
final class ManagedFiles
{
    public const array DIRECTORIES = ['src/', 'vendor/', 'bin/', 'public/', 'migrations/', 'docs/'];
    public const array FILES = [
        'config/config.example.php', 'composer.json', 'composer.lock', 'schema.sql',
        'README.md', 'AGENTS.md', 'CLAUDE.md', 'LICENSE', 'release.json', 'manifest.json',
    ];

    public static function isManaged(string $path): bool
    {
        if ($path === '' || str_starts_with($path, '/') || str_contains($path, '\\') || str_contains($path, ':')
            || str_contains($path, "\0") || in_array('..', explode('/', $path), true) || in_array('.', explode('/', $path), true)) {
            return false;
        }
        if (in_array($path, self::FILES, true)) {
            return true;
        }
        foreach (self::DIRECTORIES as $dir) {
            if (str_starts_with($path, $dir) && strlen($path) > strlen($dir) && !str_ends_with($path, '/')) {
                return true;
            }
        }

        return false;
    }

    /**
     * SHA-256 of every managed file below $root, except manifest.json itself.
     *
     * @return array<string, string> relative path => sha256
     */
    public static function scan(string $root): array
    {
        $root = rtrim(str_replace('\\', '/', $root), '/');
        $files = [];
        foreach (self::FILES as $file) {
            if ($file !== 'manifest.json' && is_file("{$root}/{$file}")) {
                $files[$file] = (string) hash_file('sha256', "{$root}/{$file}");
            }
        }
        foreach (self::DIRECTORIES as $dir) {
            if (!is_dir($root . '/' . $dir)) {
                continue;
            }
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/' . $dir, \FilesystemIterator::SKIP_DOTS));
            foreach ($it as $file) {
                /** @var \SplFileInfo $file */
                if ($file->isFile()) {
                    $rel = substr(str_replace('\\', '/', $file->getPathname()), strlen($root) + 1);
                    $files[$rel] = (string) hash_file('sha256', $file->getPathname());
                }
            }
        }
        ksort($files, SORT_STRING);

        return $files;
    }

    /**
     * The manifest of the running installation: manifest.json from the release
     * that installed it, or a scan when it was installed without one.
     *
     * @return array<string, string>
     */
    public static function installed(string $root): array
    {
        $manifest = $root . '/manifest.json';
        if (is_file($manifest)) {
            $data = json_decode((string) file_get_contents($manifest), true);
            if (is_array($data) && is_array($data['files'] ?? null)) {
                return array_map('strval', $data['files']);
            }
        }

        return self::scan($root);
    }
}
